<?php

namespace App\Console\Commands;

use App\Models\Evaluation;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Xuất dữ liệu minh chứng có nhãn (để tinh chỉnh/đánh giá mô hình AI) và đo độ chính xác của kết luận tự động.
 * Nhãn = kết luận lãnh đạo đặt lại (kể cả khi đồng ý với hệ thống): mỗi dòng JSONL là 1 tiêu chí của 1 bài,
 * gồm nội dung tiêu chí, đơn vị, dữ kiện đọc từ tệp, kết luận tự động và nhãn.
 *   docker compose exec app php artisan evidence:dataset            # chỉ in độ chính xác
 *   docker compose exec app php artisan evidence:dataset --out=storage/app/evidence-dataset.jsonl
 */
class EvidenceDataset extends Command
{
    protected $signature = 'evidence:dataset {--out= : Tệp JSONL xuất ra (bỏ trống = chỉ in độ chính xác)} {--all : Xuất cả tiêu chí chưa có nhãn}';

    protected $description = 'Xuất dữ liệu minh chứng có nhãn của lãnh đạo và đo độ chính xác kết luận tự động';

    public function handle(TenantContext $context): int
    {
        $rows = [];
        foreach (Tenant::all() as $tenant) {
            $context->set($tenant);
            Evaluation::with('attachments')->whereNotNull('criteria_version_id')->orderBy('id')
                ->each(function (Evaluation $e) use ($tenant, &$rows) {
                    foreach ($e->result['functions'] ?? [] as $funcKey => $f) {
                        foreach ($f['details'] as $d) {
                            $ev = $d['evidence'] ?? null;
                            if (! $ev || (! isset($ev['review']) && ! $this->option('all'))) {
                                continue;
                            }
                            $key = "$funcKey.{$d['question_id']}";
                            $rows[] = [
                                'tenant' => $tenant->code,
                                'evaluation_id' => $e->id,
                                'question_key' => $key,
                                'criterion' => $d['question'],
                                'unit' => $e->organization_name,
                                'auto' => $ev['auto_status'] ?? $ev['status'],
                                'label' => $ev['review']['verdict'] ?? null,
                                'label_reason' => $ev['review']['reason'] ?? null,
                                'files' => $e->attachments->where('question_key', $key)->map(fn ($a) => [
                                    'attachment_id' => $a->id,
                                    'name' => $a->original_name,
                                    'object_key' => $a->object_key,   // tải tệp gốc từ S3 khi cần huấn luyện
                                    'analysis' => $a->analysis,
                                ])->values(),
                            ];
                        }
                    }
                });
        }
        $context->clear();

        if ($out = $this->option('out')) {
            file_put_contents(base_path($out), implode("\n", array_map(fn ($r) => json_encode($r, JSON_UNESCAPED_UNICODE), $rows)).($rows ? "\n" : ''));
            $this->info('Đã xuất '.count($rows)." dòng vào $out");
        }

        $labelled = array_filter($rows, fn ($r) => $r['label']);
        if (! $labelled) {
            $this->warn('Chưa có kết luận nào của lãnh đạo để đo độ chính xác.');

            return self::SUCCESS;
        }
        $verdicts = ['pass', 'review', 'fail'];
        $matrix = array_fill_keys($verdicts, array_fill_keys($verdicts, 0));
        foreach ($labelled as $r) {
            if (isset($matrix[$r['auto']][$r['label']])) {
                $matrix[$r['auto']][$r['label']]++;
            }
        }
        $correct = array_sum(array_map(fn ($v) => $matrix[$v][$v], $verdicts));
        $this->info(sprintf('Kết luận tự động khớp lãnh đạo: %d/%d (%.1f%%)', $correct, count($labelled), 100 * $correct / count($labelled)));
        $this->table(['Tự động \\ Lãnh đạo', 'Đạt', 'Cần xem lại', 'Không đạt'],
            array_map(fn ($v) => [['pass' => 'Đạt', 'review' => 'Cần xem lại', 'fail' => 'Không đạt'][$v], ...array_values($matrix[$v])], $verdicts));

        return self::SUCCESS;
    }
}
