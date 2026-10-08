<?php

namespace App\Domain\Evaluation;

use App\Domain\Scoring\Grade;
use App\Models\Evaluation;
use App\Support\Vietnamese;
use App\Support\XlsxWriter;

/** Xuất 1 bài đánh giá ra Excel, cùng cột với file kết quả của hệ cũ. */
final class EvaluationExport
{
    public const HEADER = ['Thời gian', 'Tổ chức', 'Chức năng', 'Trọng số', 'Đt1', 'Đt2', 'Đt3', 'Đt4',
        'ĐT', 'Điểm quy đổi', 'Tổng E', 'Xếp loại', 'Nhóm', 'Câu hỏi', 'Có/Không',
        'Điểm câu hỏi', 'Chú thích', 'Minh chứng', 'Giải thích'];

    public function filename(Evaluation $e): string
    {
        return $e->submitted_at->setTimezone('Asia/Ho_Chi_Minh')->format('Ymd_His').'_'
            .(Vietnamese::slug($e->organization?->name ?? $e->organization_name) ?: 'ket_qua').'.xlsx';
    }

    public function xlsx(Evaluation $e): string
    {
        return XlsxWriter::build('Ket qua danh gia', [self::HEADER, ...$this->rows($e)]);
    }

    /** File tổng hợp: lần nộp cuối của mọi đơn vị, nối liền như results.tsv của hệ cũ. */
    public function summaryXlsx(iterable $evaluations): string
    {
        $rows = [self::HEADER];
        foreach ($evaluations as $e) {
            array_push($rows, ...$this->rows($e));
        }

        return XlsxWriter::build('Tong hop', $rows);
    }

    /** Các dòng chi tiết (từng câu hỏi) của 1 bài, cùng thứ tự cột với HEADER */
    public function rows(Evaluation $e): array
    {
        $time = $e->submitted_at->setTimezone('Asia/Ho_Chi_Minh')->format('Y-m-d H:i:s');
        $grade = Grade::tryFrom((string) $e->grade)?->label('vi') ?? $e->grade;
        $answers = $e->answers['answers'] ?? [];
        $legacy = ! empty($e->answers['legacy']);
        $files = $e->attachments->groupBy('question_key');

        $rows = [];
        foreach ($e->result['functions'] ?? [] as $funcKey => $f) {
            foreach ($f['details'] as $d) {
                $a = $answers[$funcKey][$d['question_id'] ?? ''] ?? [];
                $evidence = trim($a['evidence_text'] ?? '');
                if (! $legacy) {
                    // giống hệ cũ: "Mô tả: ... | Tệp: a.pdf, b.pdf"
                    $names = $files->get("$funcKey.{$d['question_id']}")?->pluck('original_name')->implode(', ');
                    $evidence = implode(' | ', array_filter([
                        $evidence !== '' ? "Mô tả: $evidence" : null,
                        $names ? "Tệp: $names" : null,
                    ]));
                }

                $rows[] = [
                    $time, $e->organization_name, $f['name'], round($f['weight'] * 100, 2).'%',
                    round($f['dt1'], 2), round($f['dt2'], 2), round($f['dt3'], 2), round($f['dt4'], 2), round($f['dt'], 2),
                    round($f['weighted'], 2), round((float) $e->total_score, 2), $grade,
                    $d['group'], $d['question'], $d['yes'] ? 'Có' : 'Không',
                    $d['score'], $a['note'] ?? '', $evidence, $d['explanation'] ?? '',
                ];
            }
        }

        return $rows;
    }
}
