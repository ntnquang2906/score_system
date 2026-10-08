<?php

namespace App\Domain\Evaluation;

use App\Domain\Scoring\EvaluationScorer;
use App\Models\AuditLog;
use App\Models\CriteriaVersion;
use App\Models\Evaluation;
use App\Models\Organization;
use App\Models\User;
use App\Support\UnitName;
use App\Support\Vietnamese;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Nộp 1 bài đánh giá: kiểm tra -> chấm điểm -> lưu bài, tệp minh chứng (S3) và nhật ký. */
final class SubmitEvaluation
{
    public function __construct(private EvaluationScorer $scorer) {}

    /** @param array<string, array<string, UploadedFile[]>> $files */
    public function handle(User $user, array $data, array $files, ?string $ip = null, ?string $userAgent = null): Evaluation
    {
        $criteria = CriteriaVersion::where('is_active', true)->latest('id')->first()
            ?? throw ValidationException::withMessages(['criteria' => 'Chưa có bộ tiêu chí đang áp dụng.']);

        // Tài khoản đơn vị chỉ được nộp cho chính đơn vị mình
        $isUnit = $user->hasAnyRole('unit') && ! $user->hasAnyRole('admin', 'editor');
        $validated = EvaluationRules::validate($criteria->definition, $data, $files, requireOrganizationName: ! $isUnit);
        $orgName = $isUnit ? ($user->unit_name ?: $user->organization?->name) : $validated['organization_name'];
        if (! $orgName) {
            throw ValidationException::withMessages(['organization_name' => 'Tài khoản đơn vị chưa được gán tên đơn vị, liên hệ quản trị.']);
        }

        $answers = $this->normalizeAnswers($validated['answers'] ?? [], $validated['functions']);
        $result = $this->scorer->score($criteria->definition, $validated['functions'], $validated['weights'], $answers);

        $stored = [];
        try {
            return DB::transaction(function () use ($user, $criteria, $validated, $answers, $result, $orgName, $files, $ip, $userAgent, &$stored) {
                $organization = $this->organizationFor($orgName);

                $evaluation = Evaluation::create([
                    'organization_id' => $organization->id,
                    'criteria_version_id' => $criteria->id,
                    'submitted_by' => $user->id,
                    'organization_name' => trim($orgName),
                    'functions' => array_values($validated['functions']),
                    'answers' => ['weights' => array_map('floatval', array_intersect_key($validated['weights'], array_flip($validated['functions']))), 'answers' => $answers],
                    'result' => $result,
                    'total_score' => round($result['total'], 2),
                    'grade' => $result['grade'],
                    'submitted_at' => now(),
                ]);

                $this->storeFiles($user->tenant->code, $evaluation, $files, $stored);

                AuditLog::create([
                    'user_id' => $user->id,
                    'action' => 'evaluation.submitted',
                    'subject_type' => 'evaluation',
                    'subject_id' => $evaluation->id,
                    'changes' => ['total' => $evaluation->total_score, 'grade' => $evaluation->grade],
                    'ip_address' => $ip,
                    'user_agent' => $userAgent,
                ]);

                return $evaluation;
            });
        } catch (Throwable $e) {
            // DB rollback thì xoá luôn các tệp đã đẩy lên S3
            if ($stored) {
                Storage::disk('s3')->delete($stored);
            }
            throw $e;
        }
    }

    /**
     * Sửa 1 bài đã nộp (admin/editor):
     *  - bài nộp trên hệ mới: kiểm tra + chấm lại theo đúng bộ tiêu chí bài đó đã dùng; xoá/thêm tệp
     *  - bài nhập từ hệ cũ (không có số liệu gốc): chỉ đổi tên đơn vị
     * Ghi nhật ký giá trị trước/sau.
     *
     * @param  int[]  $removeAttachmentIds
     */
    public function update(User $user, Evaluation $evaluation, array $data, array $files, array $removeAttachmentIds = [], ?string $ip = null, ?string $userAgent = null): Evaluation
    {
        $before = ['organization' => $evaluation->organization_name, 'total' => (float) $evaluation->total_score, 'grade' => $evaluation->grade];
        $legacy = ! empty($evaluation->answers['legacy']) || ! $evaluation->criteria_version_id;

        if ($legacy) {
            $orgName = trim((string) ($data['organization_name'] ?? ''));
            if ($orgName === '') {
                throw ValidationException::withMessages(['organization_name' => 'Vui lòng nhập tên tổ chức.']);
            }

            return DB::transaction(function () use ($user, $evaluation, $orgName, $before, $ip, $userAgent) {
                $evaluation->update([
                    'organization_id' => $this->organizationFor($orgName)->id,
                    'organization_name' => $orgName,
                ]);
                $this->audit($user, $evaluation, $before, $ip, $userAgent);

                return $evaluation;
            });
        }

        $criteria = CriteriaVersion::findOrFail($evaluation->criteria_version_id);
        $remove = $evaluation->attachments()->whereIn('id', $removeAttachmentIds)->get();
        $existing = [];
        foreach ($evaluation->attachments()->whereNotIn('id', $remove->pluck('id'))->get() as $a) {
            [$f, $q] = array_pad(explode('.', (string) $a->question_key, 2), 2, null);
            $existing[$f][$q] = true;
        }

        $validated = EvaluationRules::validate($criteria->definition, $data, $files, requireOrganizationName: true, existingFiles: $existing);
        $answers = $this->normalizeAnswers($validated['answers'] ?? [], $validated['functions']);
        $result = $this->scorer->score($criteria->definition, $validated['functions'], $validated['weights'], $answers);

        $stored = [];
        try {
            $evaluation = DB::transaction(function () use ($user, $evaluation, $validated, $answers, $result, $files, $remove, $before, $ip, $userAgent, &$stored) {
                $evaluation->update([
                    'organization_id' => $this->organizationFor($validated['organization_name'])->id,
                    'organization_name' => trim($validated['organization_name']),
                    'functions' => array_values($validated['functions']),
                    'answers' => ['weights' => array_map('floatval', array_intersect_key($validated['weights'], array_flip($validated['functions']))), 'answers' => $answers],
                    'result' => $result,
                    'total_score' => round($result['total'], 2),
                    'grade' => $result['grade'],
                ]);
                $this->storeFiles($user->tenant->code, $evaluation, $files, $stored);
                $removedKeys = $remove->pluck('object_key')->all();
                $evaluation->attachments()->whereIn('id', $remove->pluck('id'))->delete();
                $this->audit($user, $evaluation, $before + ['removed_files' => $remove->pluck('original_name')->all()], $ip, $userAgent);

                return [$evaluation, $removedKeys];
            });
        } catch (Throwable $e) {
            if ($stored) {
                Storage::disk('s3')->delete($stored);
            }
            throw $e;
        }

        // DB đã lưu xong mới xoá tệp cũ trên S3 (nếu lỗi ở bước trên thì tệp cũ vẫn còn nguyên)
        [$evaluation, $removedKeys] = $evaluation;
        if ($removedKeys) {
            Storage::disk('s3')->delete($removedKeys);
        }

        return $evaluation;
    }

    private function organizationFor(string $name): Organization
    {
        return Organization::firstOrCreate(
            ['normalized_key' => UnitName::key($name)],
            ['name' => UnitName::display($name)],
        );
    }

    private function audit(User $user, Evaluation $evaluation, array $before, ?string $ip, ?string $userAgent): void
    {
        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'evaluation.updated',
            'subject_type' => 'evaluation',
            'subject_id' => $evaluation->id,
            'changes' => [
                'before' => $before,
                'after' => ['organization' => $evaluation->organization_name, 'total' => (float) $evaluation->total_score, 'grade' => $evaluation->grade],
            ],
            'ip_address' => $ip,
            'user_agent' => $userAgent,
        ]);
    }

    /** Đẩy tệp minh chứng lên S3 và ghi bảng attachments; $stored nhận danh sách key đã đẩy (để dọn nếu lỗi) */
    private function storeFiles(string $tenantCode, Evaluation $evaluation, array $files, array &$stored): void
    {
        foreach ($files as $funcKey => $byQuestion) {
            foreach ($byQuestion as $questionId => $uploads) {
                foreach ((array) $uploads as $file) {
                    $key = sprintf('%s/evaluations/%d/%s/%s/%s_%s',
                        $tenantCode, $evaluation->id, $funcKey, $questionId,
                        Str::ulid(), Vietnamese::slug($file->getClientOriginalName()) ?: 'file');
                    Storage::disk('s3')->putFileAs(dirname($key), $file, basename($key));
                    $stored[] = $key;

                    $evaluation->attachments()->create([
                        'question_key' => "$funcKey.$questionId",
                        'original_name' => $file->getClientOriginalName(),
                        'object_key' => $key,
                        'mime_type' => $file->getClientMimeType(),
                        'size' => $file->getSize(),
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
