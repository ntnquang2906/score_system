<?php

namespace App\Domain\Evaluation;

use App\Domain\Evidence\ApplyEvidenceChecks;
use App\Domain\Scoring\EvaluationScorer;
use App\Jobs\CheckEvaluationEvidence;
use App\Models\AuditLog;
use App\Models\CriteriaVersion;
use App\Models\Evaluation;
use App\Models\Organization;
use App\Models\User;
use App\Support\UnitName;
use App\Support\Vietnamese;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Nộp 1 bài đánh giá: kiểm tra -> chấm điểm -> lưu bài, tệp minh chứng (S3) và nhật ký.
 * Bật kiểm tra minh chứng (config/evidence.php): kết luận ngay các câu không có tệp, rồi đọc tệp chạy nền.
 */
final class SubmitEvaluation
{
    /** Key S3 đã đẩy trong transaction đang chạy (để dọn nếu lỗi) */
    private array $stored = [];

    public function __construct(private EvaluationScorer $scorer, private ApplyEvidenceChecks $evidence) {}

    /** @param array<string, array<string, UploadedFile[]>> $files */
    public function handle(User $user, array $data, array $files, ?Request $request = null): Evaluation
    {
        $criteria = CriteriaVersion::where('is_active', true)->latest('id')->first()
            ?? throw ValidationException::withMessages(['criteria' => 'Chưa có bộ tiêu chí đang áp dụng.']);

        // Tài khoản đơn vị đã gán đơn vị chỉ được nộp cho chính đơn vị mình; tài khoản tạm (chưa gán) tự nhập tên đơn vị
        $fixedOrg = $user->hasAnyRole('unit') && ! $user->hasAnyRole('admin', 'editor') ? ($user->unit_name ?: $user->organization?->name) : null;
        $validated = EvaluationRules::validate($criteria->definition, $data, $files, requireOrganizationName: ! $fixedOrg);
        $orgName = $fixedOrg ?: $validated['organization_name'];
        $scored = $this->score($criteria->definition, $validated);

        $evaluation = $this->transaction(function () use ($user, $criteria, $orgName, $scored, $files, $request) {
            $evaluation = Evaluation::create($scored + $this->organizationFields($orgName) + [
                'criteria_version_id' => $criteria->id,
                'submitted_by' => $user->id,
                'submitted_at' => now(),
            ]);
            $this->storeFiles($user->tenant->code, $evaluation, $files);
            AuditLog::record('evaluation.submitted', $evaluation, ['total' => $evaluation->total_score, 'grade' => $evaluation->grade], $request);

            return $evaluation;
        });

        return $this->checkEvidence($evaluation);
    }

    /**
     * Sửa 1 bài đã nộp (admin/editor):
     *  - bài nộp trên hệ mới: kiểm tra + chấm lại theo đúng bộ tiêu chí bài đó đã dùng; xoá/thêm tệp
     *  - bài nhập từ hệ cũ (không có số liệu gốc): chỉ đổi tên đơn vị
     * Ghi nhật ký giá trị trước/sau.
     *
     * @param  int[]  $removeAttachmentIds
     */
    public function update(Evaluation $evaluation, array $data, array $files, array $removeAttachmentIds = [], ?Request $request = null): Evaluation
    {
        $before = ['organization' => $evaluation->organization_name, 'total' => (float) $evaluation->total_score, 'grade' => $evaluation->grade];

        if ($evaluation->isLegacy()) {
            $orgName = trim((string) ($data['organization_name'] ?? ''));
            if ($orgName === '') {
                throw ValidationException::withMessages(['organization_name' => 'Vui lòng nhập tên tổ chức.']);
            }

            return DB::transaction(function () use ($evaluation, $orgName, $before, $request) {
                $evaluation->update($this->organizationFields($orgName));
                $this->auditUpdate($evaluation, $before, $request);

                return $evaluation;
            });
        }

        $remove = $evaluation->attachments()->whereIn('id', $removeAttachmentIds)->get();
        $existing = [];
        foreach ($evaluation->attachments()->whereNotIn('id', $remove->pluck('id'))->get() as $a) {
            [$f, $q] = array_pad(explode('.', (string) $a->question_key, 2), 2, null);
            $existing[$f][$q] = true;
        }

        $definition = $evaluation->criteriaVersion->definition;
        $validated = EvaluationRules::validate($definition, $data, $files, requireOrganizationName: true, existingFiles: $existing);
        $scored = $this->score($definition, $validated);

        $this->transaction(function () use ($evaluation, $validated, $scored, $files, $remove, $before, $request) {
            $evaluation->update($scored + $this->organizationFields($validated['organization_name']));
            $this->storeFiles($evaluation->tenant->code, $evaluation, $files);
            $evaluation->attachments()->whereIn('id', $remove->pluck('id'))->delete();
            $this->auditUpdate($evaluation, $before + ['removed_files' => $remove->pluck('original_name')->all()], $request);
        });

        // DB đã lưu xong mới xoá tệp cũ trên S3 (nếu lỗi ở bước trên thì tệp cũ vẫn còn nguyên)
        if ($remove->isNotEmpty()) {
            Storage::disk('s3')->delete($remove->pluck('object_key')->all());
        }

        return $this->checkEvidence($evaluation);
    }

    /** Chạy trong transaction; lỗi thì xoá luôn các tệp đã đẩy lên S3 */
    private function transaction(callable $work): mixed
    {
        $this->stored = [];
        try {
            return DB::transaction($work);
        } catch (Throwable $e) {
            if ($this->stored) {
                Storage::disk('s3')->delete($this->stored);
            }
            throw $e;
        }
    }

    /** Chấm điểm và các cột lưu kèm */
    private function score(array $definition, array $validated): array
    {
        $functions = array_values($validated['functions']);
        $answers = $this->normalizeAnswers($validated['answers'] ?? [], $functions);
        $result = $this->scorer->score($definition, $functions, $validated['weights'], $answers);

        return [
            'functions' => $functions,
            'answers' => ['weights' => array_map('floatval', array_intersect_key($validated['weights'], array_flip($functions))), 'answers' => $answers],
            'result' => $result,
            'total_score' => round($result['total'], 2),
            'grade' => $result['grade'],
        ];
    }

    private function organizationFields(string $name): array
    {
        $organization = Organization::firstOrCreate(
            ['normalized_key' => UnitName::key($name)],
            ['name' => UnitName::display($name)],
        );

        return ['organization_id' => $organization->id, 'organization_name' => trim($name)];
    }

    /** Kết luận minh chứng ngay (câu không có tệp, tệp đã đọc) và giao việc đọc các tệp mới cho hàng đợi */
    private function checkEvidence(Evaluation $evaluation): Evaluation
    {
        if (! ApplyEvidenceChecks::enabledFor($evaluation)) {
            return $evaluation;
        }
        $this->evidence->apply($evaluation);
        if ($evaluation->attachments()->where('analysis_status', 'pending')->exists()) {
            CheckEvaluationEvidence::dispatch($evaluation->id, $evaluation->tenant_id);
        }

        return $evaluation->refresh();
    }

    private function auditUpdate(Evaluation $evaluation, array $before, ?Request $request): void
    {
        AuditLog::record('evaluation.updated', $evaluation, [
            'before' => $before,
            'after' => ['organization' => $evaluation->organization_name, 'total' => (float) $evaluation->total_score, 'grade' => $evaluation->grade],
        ], $request);
    }

    /** Đẩy tệp minh chứng lên S3 và ghi bảng attachments */
    private function storeFiles(string $tenantCode, Evaluation $evaluation, array $files): void
    {
        foreach ($files as $funcKey => $byQuestion) {
            foreach ($byQuestion as $questionId => $uploads) {
                foreach ((array) $uploads as $file) {
                    $key = sprintf('%s/evaluations/%d/%s/%s/%s_%s',
                        $tenantCode, $evaluation->id, $funcKey, $questionId,
                        Str::ulid(), Vietnamese::slug($file->getClientOriginalName()) ?: 'file');
                    Storage::disk('s3')->putFileAs(dirname($key), $file, basename($key));
                    $this->stored[] = $key;

                    $evaluation->attachments()->create([
                        'question_key' => "$funcKey.$questionId",
                        'original_name' => $file->getClientOriginalName(),
                        'object_key' => $key,
                        'mime_type' => $file->getClientMimeType(),
                        'size' => $file->getSize(),
                        'analysis_status' => config('evidence.enabled') ? 'pending' : null,
                    ]);
                }
            }
        }
    }

    private function normalizeAnswers(array $answers, array $functions): array
    {
        $out = [];
        foreach ($functions as $funcKey) {
            foreach ($answers[$funcKey] ?? [] as $qid => $a) {
                $out[$funcKey][$qid] = [
                    'yes' => in_array($a['yes'] ?? null, [true, 1, '1', 'true'], true) ? '1' : '0',
                    'inputs' => array_map(fn ($v) => (string) $v, $a['inputs'] ?? []),
                    'note' => trim((string) ($a['note'] ?? '')),
                    'evidence_text' => trim((string) ($a['evidence_text'] ?? '')),
                ];
            }
        }

        return $out;
    }
}
