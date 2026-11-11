<?php

namespace App\Jobs;

use App\Domain\Evidence\ApplyEvidenceChecks;
use App\Domain\Evidence\EvidenceAnalyzer;
use App\Models\Evaluation;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/** Đọc lần lượt các tệp minh chứng đang chờ của 1 bài; đọc xong tệp nào thì kết luận và chấm lại ngay. */
class CheckEvaluationEvidence implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public function __construct(public int $evaluationId, public int $tenantId)
    {
        $this->onQueue(config('evidence.queue'));
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping((string) $this->evaluationId))->releaseAfter(30)->expireAfter(1000)];
    }

    public function handle(TenantContext $tenant, EvidenceAnalyzer $analyzer, ApplyEvidenceChecks $checks): void
    {
        $previous = $tenant->get();
        $tenant->set(Tenant::findOrFail($this->tenantId));
        try {
            $evaluation = Evaluation::find($this->evaluationId);
            if (! $evaluation) {
                return;
            }
            $questions = [];
            foreach ($evaluation->criteriaVersion->definition['functions'] as $funcKey => $func) {
                foreach ($func['groups'] as $group) {
                    foreach ($group['criteria'] as $q) {
                        $questions["$funcKey.{$q['id']}"] = $q;
                    }
                }
            }
            foreach ($evaluation->attachments()->where('analysis_status', 'pending')->orderBy('id')->get() as $attachment) {
                $q = $questions[$attachment->question_key] ?? null;
                $analyzer->analyze($attachment, $q ? [
                    'criterion' => $q['text'],
                    'documents' => $q['evidence']['documents'] ?? [],
                    'keywords' => $q['evidence']['keywords'] ?? [],
                    'unit' => $evaluation->organization_name,
                ] : []);
                $checks->apply($evaluation->refresh());
            }
        } finally {
            $previous ? $tenant->set($previous) : $tenant->clear();
        }
    }
}
