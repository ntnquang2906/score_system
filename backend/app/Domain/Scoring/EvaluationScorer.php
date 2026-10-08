<?php

namespace App\Domain\Scoring;

use InvalidArgumentException;

/**
 * Chấm cả bài đánh giá:
 *   - Mỗi chức năng: Đt1..Đt4 = tổng điểm câu hỏi theo nhóm, ĐT = Đt1+Đt2+Đt3+Đt4
 *   - Điểm quy đổi = ĐT × trọng số (%) / 100
 *   - Tổng E = tổng điểm quy đổi các chức năng đã chọn; xếp loại A/B/C/D theo E
 */
final class EvaluationScorer
{
    public const GROUPS = ['DT1', 'DT2', 'DT3', 'DT4'];

    public function __construct(private QuestionScorer $questions = new QuestionScorer) {}

    /**
     * @param  array  $definition  bộ tiêu chí (nội dung criteria.json)
     * @param  string[]  $functions  các chức năng đã chọn, vd ['basic', 'tech']
     * @param  array<string, float|string>  $weights  trọng số (%) theo chức năng, tổng = 100
     * @param  array  $answers  [chức năng][mã câu hỏi] => ['yes' => '1', 'inputs' => [...], 'note' => ...]
     */
    public function score(array $definition, array $functions, array $weights, array $answers): array
    {
        $results = [];
        $total = 0;

        foreach ($functions as $funcKey) {
            $func = $definition['functions'][$funcKey] ?? throw new InvalidArgumentException("Chức năng không tồn tại: {$funcKey}");

            $groupScores = array_fill_keys(self::GROUPS, 0);
            $details = [];

            foreach ($func['groups'] as $group) {
                foreach ($group['criteria'] as $q) {
                    $answer = $answers[$funcKey][$q['id']] ?? [];
                    $result = $this->questions->score($q, $answer);
                    $groupScores[$group['id']] += $result->score;

                    $details[] = [
                        'group' => $group['id'],
                        'question_id' => $q['id'],
                        'question' => $q['text'],
                        'question_en' => $q['text_en'] ?? $q['text'],
                        'max' => $q['max'],
                        'yes' => QuestionScorer::isYes($answer['yes'] ?? null),
                        'score' => $result->score,
                        'explanation' => $result->explainVi,
                        'explanation_en' => $result->explainEn,
                    ];
                }
            }

            $dt = array_sum($groupScores);
            $weight = ((float) ($weights[$funcKey] ?? 0)) / 100;
            $weighted = $dt * $weight;
            $total += $weighted;

            $results[$funcKey] = [
                'name' => $func['name'],
                'name_en' => $func['name_en'] ?? $func['name'],
                'dt1' => $groupScores['DT1'],
                'dt2' => $groupScores['DT2'],
                'dt3' => $groupScores['DT3'],
                'dt4' => $groupScores['DT4'],
                'dt' => $dt,
                'weight' => $weight,
                'weighted' => $weighted,
                'details' => $details,
            ];
        }

        $grade = Grade::fromTotal($total);

        return [
            'functions' => $results,
            'total' => $total,
            'grade' => $grade->value,
            'grade_label' => $grade->label('vi'),
            'grade_label_en' => $grade->label('en'),
        ];
    }
}
