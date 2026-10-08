<?php

namespace App\Domain\Scoring;

/** Điểm của 1 câu hỏi kèm lời giải thích song ngữ. */
final class ScoreResult
{
    public function __construct(
        public readonly int|float $score,
        public readonly string $explainVi,
        public readonly string $explainEn,
    ) {}

    public function toArray(): array
    {
        return ['score' => $this->score, 'explain_vi' => $this->explainVi, 'explain_en' => $this->explainEn];
    }
}
