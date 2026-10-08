<?php

namespace Tests\Unit;

use App\Support\UnitName;
use PHPUnit\Framework\TestCase;

class UnitNameTest extends TestCase
{
    public function test_same_unit_written_differently_gets_same_key(): void
    {
        $expected = 'truong_dai_hoc_cong_nghe';
        foreach ([
            'Trường Đại học Công nghệ',
            'Trường Đại học Công nghệ - ĐHQGHN',
            'Trường Đại học Công nghệ, Đại học Quốc gia Hà Nội',
            'truong dai hoc cong nghe (VNU)',
            'Truong_Dai_hoc_Cong_nghe_DHQGHN',
        ] as $name) {
            $this->assertSame($expected, UnitName::key($name), $name);
        }
    }

    public function test_display_name_keeps_diacritics(): void
    {
        $this->assertSame('Viện Công nghệ thông tin', UnitName::display('Viện Công nghệ thông tin - ĐHQGHN'));
    }
}
