<?php

namespace App\Domain\Scoring;

/** Tiện ích số dùng trong chấm điểm (giữ đúng hành vi của hệ cũ). */
final class Num
{
    public static function clamp(int|float|string $score, int|float $max): float
    {
        return min(max((float) $score, 0), (float) $max);
    }

    public static function eq(float $a, float $b): bool
    {
        return abs($a - $b) < 0.00001;
    }

    /** Định dạng số cho câu giải thích: bỏ phần thập phân thừa, tối đa 2 chữ số. */
    public static function fmt(int|float|string|null $n): string
    {
        $n = round((float) $n, 2);

        if (abs($n - round($n)) < 0.00001) {
            return (string) (int) round($n);
        }

        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }
}
