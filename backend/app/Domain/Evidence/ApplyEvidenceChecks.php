<?php

namespace App\Domain\Evidence;

use App\Domain\Scoring\EvaluationScorer;
use App\Models\AuditLog;
use App\Models\Evaluation;
use App\Models\Organization;
use Illuminate\Support\Collection;

/**
 * Kết luận minh chứng mọi tiêu chí của 1 bài (tự động + kết luận lãnh đạo đặt lại) rồi chấm lại điểm.
 * Chạy lại được bất cứ lúc nào: dữ kiện tệp đã lưu sẵn, không phải đọc lại tệp.
 */
final class ApplyEvidenceChecks
{
    public function __construct(private EvidenceChecker $checker, private EvaluationScorer $scorer) {}

    public static function enabledFor(Evaluation $evaluation): bool
    {
        return config('evidence.enabled') && ! $evaluation->isLegacy();
    }

    /** Ký hiệu bộ tệp của 1 tiêu chí: đổi tệp thì kết luận lãnh đạo đặt trước đó hết hiệu lực */
    public static function filesSignature(Collection $attachments): string
    {
        return hash('sha256', $attachments->pluck('id')->sort()->implode(','));
    }

    public function apply(Evaluation $evaluation): Evaluation
    {
        if (! self::enabledFor($evaluation)) {
            return $evaluation;
        }

        $definition = $evaluation->criteriaVersion->definition;
        $answers = $evaluation->answers['answers'] ?? [];
        $attachments = $evaluation->attachments()->orderBy('id')->get()->groupBy('question_key');
        $reviews = $evaluation->evidenceReviews()->with('reviewer')->get()->keyBy('question_key');
        $otherUnits = Organization::whereKeyNot($evaluation->organization_id)->pluck('name')->all();
        $year = $evaluation->submitted_at->year;

        $evidence = [];
        foreach ($evaluation->functions as $funcKey) {
            foreach ($definition['functions'][$funcKey]['groups'] as $group) {
                foreach ($group['criteria'] as $q) {
                    $key = "$funcKey.{$q['id']}";
                    $files = $attachments->get($key, collect());
                    $check = $this->checker->check($q, $answers[$funcKey][$q['id']] ?? [], $files->map(fn ($a) => [
                        'id' => $a->id,
                        'name' => $a->original_name,
                        'status' => $a->analysis_status ?? 'failed',
                        'analysis' => $a->analysis,
                    ])->all(), $evaluation->organization_name, $otherUnits, $year);
                    if (! $check) {
                        continue;
                    }

                    $review = $reviews->get($key);
                    if ($review && $review->files_signature !== self::filesSignature($files)) {
                        $review->delete();
                        $review = null;
                    }
                    $evidence[$funcKey][$q['id']] = $review
                        ? $this->checker->withReview($check, $review->verdict, $review->reason, $review->reviewer?->full_name, $review->updated_at->toIso8601String())
                        : $check;
                }
            }
        }

        $result = $this->scorer->score($definition, $evaluation->functions, $evaluation->answers['weights'] ?? [], $answers, $evidence);
        $before = ['total' => (float) $evaluation->total_score, 'grade' => $evaluation->grade];
        $evaluation->update(['result' => $result, 'total_score' => round($result['total'], 2), 'grade' => $result['grade']]);

        if ($before['total'] !== (float) $evaluation->total_score || $before['grade'] !== $evaluation->grade) {
            AuditLog::record('evaluation.evidence_rescored', $evaluation, [
                'before' => $before,
                'after' => ['total' => (float) $evaluation->total_score, 'grade' => $evaluation->grade],
                'evidence' => $result['evidence'] ?? null,
            ]);
        }

        return $evaluation;
    }
}
