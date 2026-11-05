<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Evidence\ApplyEvidenceChecks;
use App\Domain\Evidence\EvidenceChecker;
use App\Http\Controllers\Controller;
use App\Http\Resources\EvaluationResource;
use App\Jobs\CheckEvaluationEvidence;
use App\Models\AuditLog;
use App\Models\Evaluation;
use App\Models\EvidenceReview;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Lãnh đạo xem lại kết luận minh chứng (admin/editor). */
class EvidenceController extends Controller
{
    /**
     * Đặt lại kết luận 1 tiêu chí: verdict = pass | review | fail kèm lý do; verdict = null => trả về kết luận tự động.
     * Điểm bài được chấm lại ngay.
     */
    public function review(Request $request, Evaluation $evaluation, ApplyEvidenceChecks $checks): EvaluationResource
    {
        Gate::authorize('update', $evaluation);
        abort_unless(ApplyEvidenceChecks::enabledFor($evaluation), 422, 'Bài này không áp dụng kiểm tra minh chứng.');

        $data = $request->validate([
            'question_key' => ['required', 'string'],
            'verdict' => ['nullable', Rule::in(EvidenceChecker::VERDICTS)],
            'reason' => ['required_with:verdict', 'nullable', 'string', 'min:3', 'max:2000'],
        ], ['reason.required_with' => 'Vui lòng nhập lý do.', 'reason.min' => 'Lý do quá ngắn.']);

        [$func, $qid] = array_pad(explode('.', $data['question_key'], 2), 2, null);
        abort_unless(isset($evaluation->result['functions'][$func]) && collect($evaluation->result['functions'][$func]['details'])
            ->contains(fn ($d) => $d['question_id'] === $qid && isset($d['evidence'])), 422, 'Tiêu chí này không có kết luận minh chứng.');

        $previous = $evaluation->evidenceReviews()->where('question_key', $data['question_key'])->first();
        if ($data['verdict'] ?? null) {
            $files = $evaluation->attachments()->where('question_key', $data['question_key'])->get();
            EvidenceReview::updateOrCreate(
                ['evaluation_id' => $evaluation->id, 'question_key' => $data['question_key']],
                ['verdict' => $data['verdict'], 'reason' => $data['reason'], 'reviewed_by' => $request->user()->id,
                    'files_signature' => ApplyEvidenceChecks::filesSignature($files)],
            );
        } else {
            $previous?->delete();
        }

        AuditLog::record('evidence.reviewed', $evaluation, [
            'question_key' => $data['question_key'],
            'before' => $previous?->only('verdict', 'reason'),
            'after' => ($data['verdict'] ?? null) ? ['verdict' => $data['verdict'], 'reason' => $data['reason']] : null,
        ], $request);

        $checks->apply($evaluation);

        return new EvaluationResource($evaluation->refresh()->load('organization', 'submitter', 'attachments'));
    }

    /** Đọc lại các tệp chưa đọc được (dịch vụ đọc tệp lỗi/chưa bật lúc nộp); all=1 => đọc lại mọi tệp */
    public function recheck(Request $request, Evaluation $evaluation, ApplyEvidenceChecks $checks): EvaluationResource
    {
        Gate::authorize('update', $evaluation);
        abort_unless(ApplyEvidenceChecks::enabledFor($evaluation), 422, 'Bài này không áp dụng kiểm tra minh chứng.');

        $evaluation->attachments()
            ->when(! $request->boolean('all'), fn ($q) => $q->where(fn ($w) => $w->whereNull('analysis_status')->orWhere('analysis_status', 'failed')))
            ->update(['analysis_status' => 'pending']);
        $checks->apply($evaluation);
        CheckEvaluationEvidence::dispatch($evaluation->id, $evaluation->tenant_id);

        return new EvaluationResource($evaluation->refresh()->load('organization', 'submitter', 'attachments'));
    }
}
