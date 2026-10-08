<?php

namespace App\Console\Commands;

use App\Domain\Scoring\Grade;
use App\Models\AuditLog;
use App\Models\CriteriaVersion;
use App\Models\Evaluation;
use App\Models\Organization;
use App\Models\Tenant;
use App\Support\TenantContext;
use App\Support\UnitName;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Nhập dữ liệu hệ PHP cũ: legacy/results/*.tsv (+ bài chỉ có trong results.tsv) và legacy/uploads/.
 * Chạy lại nhiều lần an toàn: bài đã nhập (theo tên file gốc) sẽ được bỏ qua.
 *
 *   docker compose exec app php artisan legacy:import --dry-run   # xem trước, không ghi gì
 *   docker compose exec app php artisan legacy:import
 */
class ImportLegacyData extends Command
{
    protected $signature = 'legacy:import
        {path=/var/www/legacy : Thư mục hệ cũ (chứa results/, uploads/)}
        {--tenant=vnu : Mã tenant nhận dữ liệu}
        {--dry-run : Chỉ báo cáo, không ghi dữ liệu}';

    protected $description = 'Nhập kết quả đánh giá và tệp minh chứng từ hệ PHP cũ';

    /** Cột trong file .tsv cũ */
    private const COL = ['time' => 0, 'org' => 1, 'func' => 2, 'weight' => 3, 'dt1' => 4, 'dt2' => 5, 'dt3' => 6, 'dt4' => 7,
        'dt' => 8, 'weighted' => 9, 'total' => 10, 'rank' => 11, 'group' => 12, 'question' => 13, 'yes' => 14,
        'score' => 15, 'note' => 16, 'evidence' => 17, 'explanation' => 18];

    private string $path;

    private array $definition = [];

    private array $report = ['imported' => [], 'skipped' => [], 'missing_files' => [], 'unmatched_questions' => 0];

    public function handle(TenantContext $context): int
    {
        $this->path = rtrim($this->argument('path'), '/');
        if (! is_dir("{$this->path}/results")) {
            $this->error("Không thấy thư mục {$this->path}/results");

            return self::FAILURE;
        }

        $tenant = Tenant::where('code', $this->option('tenant'))->firstOrFail();
        $context->set($tenant);
        $this->definition = CriteriaVersion::where('version', 'v1')->value('definition') ?? [];

        $submissions = $this->collectSubmissions();
        $this->resolveOrganizationNames($submissions);

        foreach ($submissions as $sub) {
            if (Evaluation::where('legacy_file', $sub['legacy_file'])->exists()) {
                $this->report['skipped'][] = "{$sub['legacy_file']} (đã nhập trước đó)";

                continue;
            }
            foreach ($sub['files'] as $f) {
                if (! $this->locateFile($f['ref'])) {
                    $this->report['missing_files'][] = "{$sub['legacy_file']}: {$f['ref']}";
                }
            }
            if (! $this->option('dry-run')) {
                $this->importSubmission($tenant, $sub);
            }
            $this->report['imported'][] = sprintf('%s  |  %s  |  E=%s %s  |  %d tệp',
                $sub['submitted_at']->copy()->setTimezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i'), $sub['org_name'], $sub['total'], $sub['grade'], count($sub['files']));
        }

        $this->printReport();

        return self::SUCCESS;
    }

    /** Mỗi file chi tiết = 1 bài; thêm các bài chỉ còn trong results.tsv. */
    private function collectSubmissions(): array
    {
        $subs = [];
        $seen = [];

        $files = glob("{$this->path}/results/*.tsv");
        sort($files);
        foreach ($files as $file) {
            $name = basename($file);
            if ($name === 'results.tsv') {
                continue;
            }
            if (! preg_match('/^(\d{8}_\d{6})_(.+)\.tsv$/u', $name, $m)) {
                $this->report['skipped'][] = "$name (tên file không đúng định dạng)";

                continue;
            }
            $rows = $this->readTsv($file);
            if (! $rows) {
                $this->report['skipped'][] = "$name (file rỗng, không có câu trả lời)";

                continue;
            }
            $sub = $this->buildSubmission($rows, $name, $m[2]);
            $subs[] = $sub;
            $seen[$rows[0][self::COL['time']].'|'.$rows[0][self::COL['org']]] = true;
        }

        // Bài chỉ có trong file tổng hợp results.tsv (không còn file chi tiết)
        $groups = [];
        foreach ($this->readTsv("{$this->path}/results/results.tsv") as $row) {
            $groups[$row[self::COL['time']].'|'.$row[self::COL['org']]][] = $row;
        }
        foreach ($groups as $key => $rows) {
            if (! isset($seen[$key])) {
                $subs[] = $this->buildSubmission($rows, 'results.tsv@'.$rows[0][self::COL['time']], null);
            }
        }

        return $subs;
    }

    private function readTsv(string $file): array
    {
        if (! is_file($file)) {
            return [];
        }
        $content = file_get_contents($file);
        $content = str_starts_with($content, "\xEF\xBB\xBF") ? substr($content, 3) : $content;
        $lines = array_values(array_filter(explode("\n", $content), fn ($l) => trim($l) !== ''));
        array_shift($lines); // dòng tiêu đề

        return array_values(array_filter(
            array_map(fn ($l) => explode("\t", rtrim($l, "\r")), $lines),
            fn ($r) => count($r) >= 16,
        ));
    }

    private function buildSubmission(array $rows, string $legacyFile, ?string $fileUnit): array
    {
        $c = self::COL;
        $first = $rows[0];
        $functions = [];
        $answers = [];
        $weights = [];
        $files = [];

        foreach ($rows as $r) {
            $funcKey = $this->functionKey($r[$c['func']]);
            $weight = (float) rtrim($r[$c['weight']], '%');
            $questionId = $this->questionId($funcKey, $r[$c['question']]);
            $yes = $r[$c['yes']] === 'Có';
            $evidence = trim($r[$c['evidence']] ?? '');

            if (! isset($functions[$funcKey])) {
                $weights[$funcKey] = $weight;
                $functions[$funcKey] = [
                    'name' => $r[$c['func']],
                    'name_en' => $this->definition['functions'][$funcKey]['name_en'] ?? $r[$c['func']],
                    'dt1' => (float) $r[$c['dt1']], 'dt2' => (float) $r[$c['dt2']],
                    'dt3' => (float) $r[$c['dt3']], 'dt4' => (float) $r[$c['dt4']],
                    'dt' => (float) $r[$c['dt']], 'weight' => $weight / 100, 'weighted' => (float) $r[$c['weighted']],
                    'details' => [],
                ];
            }

            $functions[$funcKey]['details'][] = [
                'group' => $r[$c['group']],
                'question_id' => $questionId,
                'question' => $r[$c['question']],
                'question_en' => $questionId ? ($this->criterion($funcKey, $questionId)['text_en'] ?? null) : null,
                'max' => $questionId ? ($this->criterion($funcKey, $questionId)['max'] ?? null) : null,
                'yes' => $yes,
                'score' => (float) $r[$c['score']],
                'explanation' => $r[$c['explanation']] ?? null,
                'explanation_en' => null,
            ];
            $answers[$funcKey][$questionId ?? 'q'.count($answers[$funcKey] ?? [])] = [
                'yes' => $yes ? '1' : '0',
                'note' => $r[$c['note']] ?? '',
                'evidence_text' => $evidence,
            ];

            foreach ($this->evidenceFiles($evidence) as $ref) {
                $files[] = ['ref' => $ref, 'question_key' => $questionId ? "$funcKey.$questionId" : null];
            }
        }

        $total = (float) $first[$c['total']];

        return [
            'legacy_file' => $legacyFile,
            'submitted_name' => trim($first[$c['org']]),
            'org_key' => UnitName::key($fileUnit !== null ? str_replace('_', ' ', $fileUnit) : $first[$c['org']]),
            'org_name' => null,
            // Hệ cũ ghi giờ theo UTC (PHP không đặt timezone); DB cũng lưu UTC
            'submitted_at' => Carbon::parse($first[$c['time']], 'UTC'),
            'functions' => $functions,
            'weights' => $weights,
            'answers' => $answers,
            'total' => $total,
            'grade' => substr($first[$c['rank']], 0, 1),
            'files' => $files,
        ];
    }

    /**
     * Tên hiển thị đơn vị: ưu tiên tên người dùng gõ có cùng khoá đơn vị (giữ dấu tiếng Việt);
     * nếu không có (vd ô "Tổ chức" bị gõ nhầm tên người) thì lấy từ tên file.
     */
    private function resolveOrganizationNames(array &$subs): void
    {
        $names = [];
        foreach ($subs as $s) {
            if (UnitName::key($s['submitted_name']) === $s['org_key']) {
                $names[$s['org_key']] ??= UnitName::display($s['submitted_name']);
            }
        }
        foreach ($subs as &$s) {
            $s['org_name'] = $names[$s['org_key']] ?? Str::of($s['org_key'])->replace('_', ' ')->ucfirst()->toString();
        }
    }

    private function importSubmission(Tenant $tenant, array $sub): void
    {
        DB::transaction(function () use ($tenant, $sub) {
            $organization = Organization::firstOrCreate(['normalized_key' => $sub['org_key']], ['name' => $sub['org_name']]);

            $evaluation = Evaluation::create([
                'organization_id' => $organization->id,
                'criteria_version_id' => null,   // không biết chắc bộ tiêu chí tại thời điểm nộp
                'organization_name' => $sub['submitted_name'],
                'functions' => array_keys($sub['functions']),
                'answers' => ['weights' => $sub['weights'], 'answers' => $sub['answers'], 'legacy' => true],
                'result' => [
                    'functions' => $sub['functions'],
                    'total' => $sub['total'],
                    'grade' => $sub['grade'],
                    'grade_label' => Grade::from($sub['grade'])->label('vi'),
                    'grade_label_en' => Grade::from($sub['grade'])->label('en'),
                    'legacy' => true,
                ],
                'total_score' => round($sub['total'], 2),
                'grade' => $sub['grade'],
                'legacy_file' => $sub['legacy_file'],
                'submitted_at' => $sub['submitted_at'],
            ]);

            foreach ($sub['files'] as $f) {
                $local = $this->locateFile($f['ref']);
                if (! $local) {
                    continue;   // đã ghi vào báo cáo; đường dẫn vẫn còn trong evidence_text
                }
                $original = preg_replace('/^\d{8}_\d{6}_[0-9a-f]{13}_/', '', basename($local));
                $key = "{$tenant->code}/evaluations/{$evaluation->id}/legacy/".basename($local);
                Storage::disk('s3')->put($key, fopen($local, 'r'));
                $evaluation->attachments()->create([
                    'question_key' => $f['question_key'],
                    'original_name' => $original,
                    'object_key' => $key,
                    'mime_type' => mime_content_type($local) ?: null,
                    'size' => filesize($local),
                ]);
            }

            AuditLog::create([
                'action' => 'evaluation.imported',
                'subject_type' => 'evaluation',
                'subject_id' => $evaluation->id,
                'changes' => ['legacy_file' => $sub['legacy_file']],
            ]);
        });
    }

    /**
     * Lấy danh sách tệp trong ô "Minh chứng". Hai định dạng của hệ cũ:
     *   "Mô tả: ... | Tệp: uploads/a.pdf, uploads/b.pdf"   (bản mới)
     *   "uploads/a.pdf, uploads/b.pdf"                       (bản đầu, chỉ có tệp)
     * Không lấy đường dẫn nằm trong phần mô tả (vd link website .../uploads/...).
     */
    private function evidenceFiles(string $evidence): array
    {
        if (preg_match('/Tệp:\s*(.+)$/u', $evidence, $m)) {
            $list = $m[1];
        } elseif (str_starts_with($evidence, 'uploads/')) {
            $list = $evidence;
        } else {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $list))));
    }

    /** Tìm tệp theo đường dẫn ghi trong bài; bản cũ ghi thiếu thư mục đơn vị nên tìm thêm theo tên tệp. */
    private function locateFile(string $ref): ?string
    {
        $direct = "{$this->path}/".ltrim($ref, '/');
        if (is_file($direct) && str_starts_with(realpath($direct), realpath("{$this->path}/uploads"))) {
            return $direct;
        }
        $matches = glob("{$this->path}/uploads/*/".basename($ref));

        return $matches[0] ?? null;
    }

    private function functionKey(string $name): string
    {
        foreach ($this->definition['functions'] ?? [] as $key => $f) {
            if ($f['name'] === $name) {
                return $key;
            }
        }

        return Str::slug($name, '_');
    }

    private function questionId(string $funcKey, string $text): ?string
    {
        foreach ($this->definition['functions'][$funcKey]['groups'] ?? [] as $group) {
            foreach ($group['criteria'] as $q) {
                if (trim($q['text']) === trim($text)) {
                    return $q['id'];
                }
            }
        }
        $this->report['unmatched_questions']++;

        return null;
    }

    private function criterion(string $funcKey, string $id): ?array
    {
        foreach ($this->definition['functions'][$funcKey]['groups'] ?? [] as $group) {
            foreach ($group['criteria'] as $q) {
                if ($q['id'] === $id) {
                    return $q;
                }
            }
        }

        return null;
    }

    private function printReport(): void
    {
        $dry = $this->option('dry-run') ? ' (DRY RUN - chưa ghi gì)' : '';
        $this->info('Đã nhập'.$dry.': '.count($this->report['imported']).' bài');
        foreach ($this->report['imported'] as $line) {
            $this->line("  + $line");
        }
        if ($this->report['skipped']) {
            $this->warn('Bỏ qua: '.count($this->report['skipped']));
            foreach ($this->report['skipped'] as $line) {
                $this->line("  - $line");
            }
        }
        if ($this->report['missing_files']) {
            $this->warn('Tệp minh chứng không tìm thấy (giữ lại dạng chữ trong mô tả):');
            foreach ($this->report['missing_files'] as $line) {
                $this->line("  ? $line");
            }
        }
        if ($this->report['unmatched_questions']) {
            $this->warn("Câu hỏi không khớp bộ tiêu chí v1 (vẫn nhập, chỉ thiếu mã câu): {$this->report['unmatched_questions']}");
        }
    }
}
