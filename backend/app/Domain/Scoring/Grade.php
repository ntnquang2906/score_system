<?php

namespace App\Domain\Scoring;

enum Grade: string
{
    case A = 'A';
    case B = 'B';
    case C = 'C';
    case D = 'D';

    public static function fromTotal(float $total): self
    {
        return match (true) {
            $total >= 80 => self::A,
            $total >= 60 => self::B,
            $total >= 40 => self::C,
            default => self::D,
        };
    }

    public function label(string $locale = 'vi'): string
    {
        return match ($this) {
            self::A => $locale === 'en' ? 'A - Excellent' : 'A - Xuất sắc',
            self::B => $locale === 'en' ? 'B - Good' : 'B - Tốt',
            self::C => $locale === 'en' ? 'C - Average' : 'C - Trung bình',
            self::D => $locale === 'en' ? 'D - Poor' : 'D - Kém',
        };
    }
}
