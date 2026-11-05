<?php

namespace Tests\Unit\Evidence;

use App\Domain\Evidence\EvidenceChecker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EvidenceCheckerTest extends TestCase
{
    private const UNIT = 'Viện Trần Nhân Tông';

    private const OTHERS = ['Trường Đại học Ngoại ngữ', 'Trường Đại học Công nghệ'];

    private const DECISION = "ĐẠI HỌC QUỐC GIA HÀ NỘI\nVIỆN TRẦN NHÂN TÔNG\nHà Nội, ngày 12 tháng 3 năm 2025\n"
        ."THỎA THUẬN HỢP TÁC\nGiữa Viện Trần Nhân Tông và Đại học Kyoto về hợp tác nghiên cứu Phật học.\n"
        ."Hai bên thống nhất triển khai đề tài chung, trao đổi cán bộ và xuất bản công trình.\nVIỆN TRƯỞNG\nNguyễn Văn A";

    private static array $definition;

    public static function setUpBeforeClass(): void
    {
        self::$definition = json_decode(file_get_contents(__DIR__.'/../../../database/data/criteria_v1.json'), true);
    }

    private function question(string $id): array
    {
        foreach (self::$definition['functions'] as $f) {
            foreach ($f['groups'] as $g) {
                foreach ($g['criteria'] as $q) {
                    if ($q['id'] === $id) {
                        return $q;
                    }
                }
            }
        }
        $this->fail("Không có tiêu chí $id");
    }

    private function file(array $analysis = [], string $status = 'done', string $name = 'QD_hop_tac.pdf'): array
    {
        return ['id' => 1, 'name' => $name, 'status' => $status, 'analysis' => $analysis + [
            'kind' => 'pdf', 'text' => self::DECISION, 'readable' => true, 'ocr_pages' => [], 'rows' => null,
            'stamps' => [['page' => 1, 'x' => 0.6, 'y' => 0.8, 'r' => 0.09, 'score' => 0.3]],
            'signatures' => [['page' => 1, 'method' => 'ink_near_stamp']],
            'signature_blocks' => [], 'warnings' => [],
        ]];
    }

    private function check(string $qid, array $files, array $inputs = [], string $yes = '1', string $description = ''): ?array
    {
        return (new EvidenceChecker)->check($this->question($qid), ['yes' => $yes, 'inputs' => $inputs, 'evidence_text' => $description], $files, self::UNIT, self::OTHERS, 2026);
    }

    public function test_description_only_needs_review_when_it_mentions_the_criterion(): void
    {
        $r = $this->check('basic_collaboration', [], description: 'Hợp tác với Đại học Kyoto (Nhật Bản) về nghiên cứu Phật học, MOU ký năm 2025');
        $this->assertSame(['review', ['text_only']], [$r['status'], $r['reasons']]);
        $this->assertStringContainsString('mô tả có nhắc tới hợp tác: “Hợp tác với Đại học Kyoto', $r['explain_vi']);

        $r = $this->check('basic_collaboration', [], description: 'Quyết định số 123/QĐ-VTNT');
        $this->assertSame(['fail', ['no_files']], [$r['status'], $r['reasons']]);
        $this->assertStringContainsString('mô tả không nhắc tới nội dung của tiêu chí (như: hợp tác', $r['explain_vi']);
    }

    public function test_answer_no_needs_no_evidence(): void
    {
        $this->assertNull($this->check('basic_collaboration', [], yes: '0'));
    }

    public function test_signed_cooperation_agreement_passes_with_explanation(): void
    {
        $r = $this->check('basic_collaboration', [$this->file()]);

        $this->assertSame('pass', $r['status']);
        $this->assertStringContainsString('«QD_hop_tac.pdf» là Thỏa Thuận (trang 1), năm 2025, có chữ ký và con dấu', $r['explain_vi']);
        $this->assertStringContainsString('Giữ nguyên điểm', $r['explain_vi']);
        $this->assertSame('yes', $r['files'][0]['unit']);
    }

    public static function failures(): array
    {
        return [
            'chỉ có mô tả' => [[], 'no_files'],
            'không đọc được' => [['text' => '', 'readable' => false], 'unreadable'],
            'không liên quan' => [['text' => "ĐẠI HỌC QUỐC GIA HÀ NỘI\nVIỆN TRẦN NHÂN TÔNG\nBẢNG LƯƠNG THÁNG 5\n".str_repeat('Nguyễn Văn B nhận 12.000.000 đồng tiền lương cơ bản và phụ cấp. ', 6)], 'irrelevant'],
            'đơn vị khác' => [['text' => str_replace('VIỆN TRẦN NHÂN TÔNG', 'TRƯỜNG ĐẠI HỌC NGOẠI NGỮ', str_replace('Viện Trần Nhân Tông', 'Trường', self::DECISION))], 'other_unit'],
        ];
    }

    #[DataProvider('failures')]
    public function test_failures_score_zero(?array $analysis, string $reason): void
    {
        $r = $this->check('basic_collaboration', $analysis === [] && $reason === 'no_files' ? [] : [$this->file($analysis)]);

        $this->assertSame('fail', $r['status']);
        $this->assertSame([$reason], $r['reasons']);
        $this->assertStringContainsString('được 0 điểm', $r['explain_vi']);
    }

    public static function reviews(): array
    {
        return [
            'thiếu dấu' => [['stamps' => []], ['no_stamp']],
            'bản đen trắng' => [['stamps' => [], 'warnings' => ['no_color']], ['no_color']],
            'có chỗ ký nhưng chưa ký' => [['signatures' => [], 'signature_blocks' => [['page' => 1, 'title' => 'VIỆN TRƯỞNG', 'signed' => false]]], ['unsigned']],
            'văn bản cũ' => [['text' => str_replace('năm 2025', 'năm 2019', self::DECISION)], ['old_document']],
            'tệp Word' => [['kind' => 'docx', 'stamps' => [], 'signatures' => [], 'warnings' => ['editable_document']], ['editable']],
            'đúng loại văn bản, lạc chủ đề' => [['text' => "VIỆN TRẦN NHÂN TÔNG\nHà Nội, ngày 1 tháng 4 năm 2025\nQUYẾT ĐỊNH\nVề việc hỗ trợ kinh phí công bố khoa học đợt 1 năm 2025.\n".str_repeat('Theo đề nghị của Phòng Hợp tác phát triển, hỗ trợ 5.000.000 đồng cho bài báo đăng trên tạp chí trong nước. ', 4)], ['uncertain_topic']],
            'không thấy tên đơn vị' => [['text' => str_replace(['VIỆN TRẦN NHÂN TÔNG', 'Viện Trần Nhân Tông'], ['VIỆN X', 'Viện X'], self::DECISION)], ['unit_not_found']],
        ];
    }

    #[DataProvider('reviews')]
    public function test_review_keeps_declared_score(array $analysis, array $reasons): void
    {
        $r = $this->check('basic_collaboration', [$this->file($analysis)]);

        $this->assertSame('review', $r['status']);
        $this->assertSame($reasons, $r['reasons']);
        $this->assertStringContainsString('Tạm giữ điểm', $r['explain_vi']);
    }

    public function test_pending_and_reader_failure(): void
    {
        $this->assertSame('pending', $this->check('basic_collaboration', [$this->file(status: 'pending')])['status']);

        $r = $this->check('basic_collaboration', [$this->file(status: 'failed')]);
        $this->assertSame(['review', ['analysis_failed']], [$r['status'], $r['reasons']]);

        $zip = $this->check('basic_collaboration', [$this->file(['kind' => 'unsupported', 'text' => '', 'readable' => false, 'warnings' => ['unsupported_format']], name: 'ho_so.zip')]);
        $this->assertSame(['review', ['unsupported_format']], [$zip['status'], $zip['reasons']]);
    }

    public function test_declared_count_is_compared_with_items_in_evidence(): void
    {
        $list = ['kind' => 'xlsx', 'text' => "STT | Tên bài báo | Tạp chí | DOI\nViện Trần Nhân Tông", 'stamps' => [], 'signatures' => [], 'warnings' => ['editable_document']];

        $short = $this->check('basic_international_publication', [$this->file($list + ['rows' => 3], name: 'bai_bao.xlsx')], ['q1' => 3, 'q2' => 2]);
        $this->assertSame(['review', ['count_short']], [$short['status'], $short['reasons']]);
        $this->assertStringContainsString('kê khai 5 bài báo nhưng minh chứng chỉ thấy 3', $short['explain_vi']);

        $enough = $this->check('basic_international_publication', [$this->file($list + ['rows' => 6], name: 'bai_bao.xlsx')], ['q1' => 3, 'q2' => 2]);
        $this->assertSame('pass', $enough['status']);
    }

    public function test_reviewer_verdict_overrides_and_keeps_automatic_result(): void
    {
        $checker = new EvidenceChecker;
        $auto = $this->check('basic_collaboration', [$this->file(['stamps' => []])]);

        $r = $checker->withReview($auto, 'fail', 'Biên bản chưa được ký chính thức', 'Lãnh đạo A', '2026-11-04T10:00:00+07:00');

        $this->assertSame(['fail', 'reviewer', 'review'], [$r['status'], $r['source'], $r['auto_status']]);
        $this->assertStringStartsWith('Lãnh đạo kết luận minh chứng không đạt: Biên bản chưa được ký chính thức. Vì vậy', $r['explain_vi']);
    }
}
