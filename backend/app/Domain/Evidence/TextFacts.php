<?php

namespace App\Domain\Evidence;

use App\Support\UnitName;
use App\Support\Vietnamese;
use Normalizer;

/**
 * Dữ kiện rút ra từ chữ của 1 tệp minh chứng: có cụm từ nào, năm ban hành, số mục liệt kê, có nhắc tên đơn vị không.
 * So khớp không phân biệt dấu/hoa thường (chữ OCR hay sai dấu: "QUYÉT ĐỊNH", "VIỆN TRÀN NHÂN TÔNG").
 */
final class TextFacts
{
    /** Chữ đã bỏ dấu, viết thường; cùng số ký tự với chữ gốc để trích dẫn đúng vị trí */
    private string $plain;

    /** Chữ đã bỏ dấu và gộp khoảng trắng (để tìm tên đơn vị xuống dòng giữa chừng) */
    private string $compact;

    public function __construct(private string $text)
    {
        if (class_exists(Normalizer::class)) {
            $this->text = Normalizer::normalize($text, Normalizer::FORM_C) ?: $text;
        }
        $this->plain = mb_strtolower(Vietnamese::stripDiacritics($this->text));
        $this->compact = self::compact($this->plain);
    }

    public static function plain(string $text): string
    {
        return mb_strtolower(Vietnamese::stripDiacritics($text));
    }

    /**
     * Cụm từ đầu tiên (theo thứ tự danh sách) xuất hiện trong đoạn [$start, $start + $limit) (null = tới hết văn bản).
     *
     * @return array{term: string, offset: int}|null offset tính theo ký tự trong cả văn bản
     */
    public function findFirst(array $terms, ?int $limit = null, int $start = 0): ?array
    {
        $haystack = mb_substr($this->plain, $start, $limit);
        foreach ($terms as $term) {
            if (preg_match(self::pattern($term), $haystack, $m, PREG_OFFSET_CAPTURE)) {
                return ['term' => $term, 'offset' => $start + mb_strlen(substr($haystack, 0, $m[0][1]))];
            }
        }

        return null;
    }

    /** Cụm từ xuất hiện sớm nhất trong $limit ký tự đầu (vd tiêu đề "QUYẾT ĐỊNH" đứng trước "Căn cứ Thông tư...") */
    public function findEarliest(array $terms, int $limit): ?array
    {
        $hits = array_filter(array_map(fn ($t) => $this->findFirst([$t], $limit), $terms));
        usort($hits, fn ($a, $b) => $a['offset'] <=> $b['offset']);

        return $hits[0] ?? null;
    }

    /** Các cụm từ có xuất hiện */
    public function found(array $terms): array
    {
        return array_values(array_filter($terms, fn ($t) => preg_match(self::pattern($t), $this->plain)));
    }

    /** Trang chứa vị trí $offset (các trang PDF ngăn cách bởi \f) */
    public function pageAt(int $offset): int
    {
        return substr_count(mb_substr($this->text, 0, $offset), "\f") + 1;
    }

    /** Đoạn trích quanh vị trí $offset, gọn 1 dòng */
    public function quote(int $offset, int $length = 140): string
    {
        $start = max(0, $offset - 20);
        $snippet = preg_replace('/\s+/u', ' ', mb_substr($this->text, $start, $length));

        return trim(($start > 0 ? '…' : '').trim($snippet).'…');
    }

    /**
     * Năm ban hành văn bản: "Hà Nội, ngày 12 tháng 3 năm 2025", "12/03/2025", "2025-03-12" ở phần đầu văn bản
     * (chữ OCR có thể lẫn ký tự lạ giữa các chữ "ngày ... tháng ... năm").
     */
    public function year(): ?int
    {
        $head = mb_substr($this->plain, 0, 3000);
        $patterns = [
            '/ngay.{0,20}?thang.{0,20}?nam\s*((?:19|20)\d{2})/su',
            '/\b\d{1,2}[\/.\-]\d{1,2}[\/.\-]((?:19|20)\d{2})\b/u',
            '/\b((?:19|20)\d{2})-\d{2}-\d{2}\b/u',
            '/\bdated?\b.{0,30}?\b((?:19|20)\d{2})\b/su',
        ];
        foreach ($patterns as $p) {
            if (preg_match($p, $head, $m)) {
                return (int) $m[1];
            }
        }

        return null;
    }

    /**
     * Số mục được liệt kê: dãy số thứ tự 1, 2, 3... ở đầu dòng (cột STT của bảng, danh sách đánh số),
     * hoặc số mã DOI khác nhau (danh mục bài báo). Lấy giá trị lớn hơn.
     */
    public function itemCount(): int
    {
        $next = 1;
        foreach (preg_split('/\R|\f/u', $this->text) as $line) {
            if (preg_match('/^\s*(\d{1,3})\s*[.)\-:|]?(?:\s|$)/u', $line, $m) && (int) $m[1] === $next) {
                $next++;
            }
        }
        preg_match_all('/\b10\.\d{4,9}\/[^\s"<>|]+/u', $this->text, $dois);

        return max($next - 1, count(array_unique($dois[0])));
    }

    /** Có nhắc tới đơn vị (tên đầy đủ, bỏ chữ "Trường", hoặc viết tắt "ĐH" cho "Đại học") */
    public function mentionsUnit(string $unitName): bool
    {
        foreach (self::unitVariants($unitName) as $variant) {
            if (str_contains($this->compact, $variant)) {
                return true;
            }
        }

        return false;
    }

    /** Đơn vị khác (trong danh sách) được nêu ở phần đầu văn bản (chỗ ghi cơ quan ban hành) */
    public function headerUnit(array $unitNames, int $limit = 1500): ?string
    {
        $head = self::compact(mb_substr($this->plain, 0, $limit));
        foreach ($unitNames as $name) {
            foreach (self::unitVariants($name) as $variant) {
                if (str_contains($head, $variant)) {
                    return $name;
                }
            }
        }

        return null;
    }

    public function length(): int
    {
        return mb_strlen(preg_replace('/\s+/u', '', $this->plain));
    }

    /** "Trường Đại học Công nghệ - ĐHQGHN" => ["truong dai hoc cong nghe", "dai hoc cong nghe", "dh cong nghe"] */
    public static function unitVariants(string $name): array
    {
        $full = self::compact(self::plain(UnitName::display($name)));
        $variants = [$full, preg_replace('/^truong /', '', $full)];
        $variants[] = str_replace('dai hoc ', 'dh ', end($variants));

        // Quá ngắn thì dễ khớp nhầm ("cong nghe")
        return array_values(array_unique(array_filter($variants, fn ($v) => mb_strlen($v) >= 8 && substr_count($v, ' ') >= 1)));
    }

    private static function compact(string $plain): string
    {
        return trim(preg_replace('/[^a-z0-9]+/u', ' ', $plain));
    }

    private static function pattern(string $term): string
    {
        $words = preg_split('/\s+/u', self::plain(trim($term)));

        return '/(?<![a-z0-9])'.implode('\s+', array_map(fn ($w) => preg_quote($w, '/'), $words)).'(?![a-z0-9])/u';
    }
}
