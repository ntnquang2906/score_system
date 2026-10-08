<?php

namespace App\Domain\Scoring;

/**
 * Tra điểm theo bảng khoảng giá trị.
 * Mỗi khoảng: {min, max, score_min, score_mid, score_max} hoặc khoảng mở {min, max: null, score}.
 * Trong khoảng đóng: dưới điểm giữa => score_min, đúng điểm giữa => score_mid, trên => score_max.
 */
final class RangeTable
{
    public static function midpointScore(float $value, array $range): int|float
    {
        if ($range['max'] === null) {
            return $range['score'] ?? $range['score_max'] ?? 0;
        }

        $mid = ((float) $range['min'] + (float) $range['max']) / 2;

        if ($value < $mid) {
            return $range['score_min'];
        }

        if (Num::eq($value, $mid)) {
            return $range['score_mid'] ?? (($range['score_min'] + $range['score_max']) / 2);
        }

        return $range['score_max'];
    }

    /** @return array{score: int|float, vi: string, en: string} */
    public static function describe(float $value, array $ranges): array
    {
        foreach ($ranges as $r) {
            $min = (float) $r['min'];
            $max = $r['max'];

            if ($max === null) {
                if ($value >= $min) {
                    $score = $r['score'] ?? $r['score_max'] ?? 0;

                    return [
                        'score' => $score,
                        'vi' => 'giá trị '.Num::fmt($value).' ≥ '.Num::fmt($min).' nên được '.Num::fmt($score).' điểm',
                        'en' => 'the value '.Num::fmt($value).' is ≥ '.Num::fmt($min).', so it received '.Num::fmt($score).' points',
                    ];
                }
            } elseif ($value >= $min && $value <= (float) $max) {
                $score = self::midpointScore($value, $r);

                return [
                    'score' => $score,
                    'vi' => 'giá trị '.Num::fmt($value).' nằm trong khoảng ['.Num::fmt($min).'-'.Num::fmt($max).'] nên được '.Num::fmt($score).' điểm',
                    'en' => 'the value '.Num::fmt($value).' falls within the ['.Num::fmt($min).'-'.Num::fmt($max).'] range, so it received '.Num::fmt($score).' points',
                ];
            }
        }

        return [
            'score' => 0,
            'vi' => 'giá trị '.Num::fmt($value).' chưa đạt ngưỡng tối thiểu nào nên được 0 điểm',
            'en' => 'the value '.Num::fmt($value).' does not reach any defined threshold, so it received 0 points',
        ];
    }
}
