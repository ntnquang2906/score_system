<?php

namespace App\Support;

/**
 * Chuẩn hoá tên đơn vị/tổ chức để nhận ra cùng 1 đơn vị dù cách gõ khác nhau:
 *   "Trường Đại học Công nghệ - ĐHQGHN", "Truong Dai hoc Cong nghe, VNU"  =>  "truong_dai_hoc_cong_nghe"
 * (bỏ dấu, bỏ cụm định danh đi kèm như "Đại học Quốc gia Hà Nội"/"VNU", không phân biệt hoa/thường).
 */
final class UnitName
{
    private const AFFILIATIONS = [
        'dai hoc quoc gia ha noi',
        'dai hoc quoc gia thanh pho ho chi minh',
        'dai hoc quoc gia tp ho chi minh',
        'dai hoc quoc gia tp hcm',
        'dhqghn',
        'dhqg tp hcm',
        'dhqg hcm',
        'dhqg',
        'vnu ha noi',
        'vnu hn',
        'vnu hcm',
        'vnu',
    ];

    public static function key(string $name): string
    {
        $text = self::stripAffiliationSegments(trim($name));
        $text = Vietnamese::stripDiacritics($text);
        $text = self::stripGluedAffiliation($text);

        return strtolower(Vietnamese::slug($text));
    }

    /** Tên hiển thị (giữ dấu) sau khi bỏ cụm định danh đi kèm. */
    public static function display(string $name): string
    {
        return trim(self::stripAffiliationSegments(trim($name)), " \t\n\r\0\x0B,-–");
    }

    private static function compareForm(string $text): string
    {
        $text = strtolower(Vietnamese::stripDiacritics($text));

        return trim(preg_replace('/[^a-z0-9]+/u', ' ', $text));
    }

    /** "Trường ĐH Công nghệ, ĐHQGHN" -> "Trường ĐH Công nghệ" */
    private static function stripAffiliationSegments(string $text): string
    {
        $kept = [];
        foreach (preg_split('/[,\-–()]+/u', $text) as $segment) {
            $segment = trim($segment);
            if ($segment !== '' && ! in_array(self::compareForm($segment), self::AFFILIATIONS, true)) {
                $kept[] = $segment;
            }
        }

        $result = trim(implode(', ', $kept));

        return $result !== '' ? $result : trim($text);
    }

    /** "Truong_Dai_hoc_Cong_nghe_DHQGHN" -> "Truong_Dai_hoc_Cong_nghe" */
    private static function stripGluedAffiliation(string $text): string
    {
        $alternatives = array_map(
            fn ($p) => preg_replace('/\s+/', '[ _]+', preg_quote($p, '/')),
            self::AFFILIATIONS,
        );
        $pattern = '/[ _]+('.implode('|', $alternatives).')[ _]*$/iu';

        while (($next = preg_replace($pattern, '', $text)) !== $text) {
            $text = trim($next);
        }

        return $text;
    }
}
