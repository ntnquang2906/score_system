<?php

namespace Tests\Unit\Scoring;

use App\Domain\Scoring\EvaluationScorer;
use App\Domain\Scoring\QuestionScorer;
use PHPUnit\Framework\TestCase;

/**
 * Đảm bảo bộ chấm điểm mới cho kết quả GIỐNG HỆT hệ cũ (legacy/process.php):
 * cùng điểm và cùng câu giải thích cho hàng nghìn bộ số liệu, kể cả giá trị biên.
 * Fixtures sinh bởi tests/Fixtures/scoring/generate_from_legacy.php.
 */
class LegacyParityTest extends TestCase
{
    private static array $fixtures;

    private static array $criteria;

    public static function setUpBeforeClass(): void
    {
        self::$fixtures = json_decode(gzdecode(file_get_contents(__DIR__.'/../../Fixtures/scoring/legacy_cases.json.gz')), true);
        self::$criteria = json_decode(file_get_contents(__DIR__.'/../../../database/data/criteria_v1.json'), true);
    }

    private function question(string $function, string $id): array
    {
        foreach (self::$criteria['functions'][$function]['groups'] as $group) {
            foreach ($group['criteria'] as $q) {
                if ($q['id'] === $id) {
                    return $q;
                }
            }
        }
        $this->fail("Không tìm thấy câu hỏi {$function}/{$id}");
    }

    public function test_every_question_case_matches_legacy(): void
    {
        $scorer = new QuestionScorer;
        $checked = 0;

        foreach (self::$fixtures['questions'] as $i => $case) {
            $q = $this->question($case['function'], $case['question']);
            $actual = $scorer->score($q, $case['answer']);
            $label = "case #{$i} {$case['function']}/{$case['question']} ".json_encode($case['answer']);

            $this->assertSame((float) $case['expected']['score'], (float) $actual->score, $label);
            $this->assertSame($case['expected']['explain_vi'], $actual->explainVi, $label);
            $this->assertSame($case['expected']['explain_en'], $actual->explainEn, $label);
            $checked++;
        }

        $this->assertGreaterThan(20000, $checked);
    }

    public function test_every_scoring_type_is_covered(): void
    {
        $types = [];
        foreach (self::$fixtures['questions'] as $case) {
            $types[$this->question($case['function'], $case['question'])['scoring']['type']] = true;
        }

        $this->assertEqualsCanonicalizing(
            array_keys($types),
            array_values(array_intersect(QuestionScorer::TYPES, array_keys($types))),
        );
        $this->assertCount(26, $types);
    }

    public function test_whole_evaluation_totals_and_grade_match_legacy(): void
    {
        $scorer = new EvaluationScorer;

        foreach (self::$fixtures['evaluations'] as $i => $case) {
            $actual = $scorer->score(self::$criteria, $case['functions'], $case['weights'], $case['answers']);

            $this->assertSame((float) $case['expected']['total'], (float) $actual['total'], "evaluation #{$i} total");
            $this->assertSame($case['expected']['grade'], $actual['grade'], "evaluation #{$i} grade");

            foreach ($case['expected']['functions'] as $fk => $exp) {
                foreach (['dt1', 'dt2', 'dt3', 'dt4', 'dt', 'weighted'] as $k) {
                    $this->assertSame((float) $exp[$k], (float) $actual['functions'][$fk][$k], "evaluation #{$i} {$fk}.{$k}");
                }
            }
        }
    }
}
