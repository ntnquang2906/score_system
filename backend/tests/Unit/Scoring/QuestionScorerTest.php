<?php

namespace Tests\Unit\Scoring;

use App\Domain\Scoring\Grade;
use App\Domain\Scoring\QuestionScorer;
use PHPUnit\Framework\TestCase;

/** Một số ví dụ dễ đọc, minh hoạ quy tắc chấm điểm. */
class QuestionScorerTest extends TestCase
{
    private const INTL_PUBLICATION = [
        'id' => 'basic_international_publication', 'text' => 'x', 'max' => 10,
        'scoring' => ['type' => 'weighted_range', 'weights' => ['q1' => 5, 'q2' => 3, 'q3' => 2, 'q4' => 1], 'ranges' => [
            ['min' => 1, 'max' => 4, 'score_min' => 1, 'score_mid' => 1.5, 'score_max' => 2],
            ['min' => 5, 'max' => 12, 'score_min' => 2, 'score_mid' => 2.5, 'score_max' => 3],
            ['min' => 13, 'max' => 19, 'score_min' => 4, 'score_mid' => 4.5, 'score_max' => 5],
            ['min' => 20, 'max' => null, 'score' => 6],
        ]],
    ];

    public function test_answer_no_gives_zero(): void
    {
        $r = (new QuestionScorer)->score(self::INTL_PUBLICATION, ['yes' => '0', 'inputs' => ['q1' => 10]]);
        $this->assertSame(0, $r->score);
    }

    public function test_weighted_range_uses_midpoint_of_range(): void
    {
        $scorer = new QuestionScorer;
        // 1 bài Q1 = 5 điểm quy đổi -> khoảng [5-12], dưới điểm giữa 8.5 -> 2 điểm
        $this->assertEquals(2, $scorer->score(self::INTL_PUBLICATION, ['yes' => '1', 'inputs' => ['q1' => 1]])->score);
        // 4 bài Q1 = 20 -> khoảng mở >= 20 -> 6 điểm
        $this->assertEquals(6, $scorer->score(self::INTL_PUBLICATION, ['yes' => true, 'inputs' => ['q1' => 4]])->score);
    }

    public function test_grade_thresholds(): void
    {
        $this->assertSame(Grade::A, Grade::fromTotal(80));
        $this->assertSame(Grade::B, Grade::fromTotal(79.99));
        $this->assertSame(Grade::C, Grade::fromTotal(40));
        $this->assertSame(Grade::D, Grade::fromTotal(39.99));
    }
}
