<?php

namespace Tests\Feature;

use App\Models\CriteriaVersion;
use App\Models\Evaluation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EvidenceCheckTest extends TestCase
{
    use RefreshDatabase;

    private array $definition;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
        config(['evidence.enabled' => true]);
        $this->definition = json_decode(file_get_contents(database_path('data/criteria_v1.json')), true);
    }

    /** Dịch vụ đọc tệp giả: trả về 1 biên bản hợp tác có dấu và chữ ký */
    private function fakeReader(): void
    {
        Http::fake(['evidence-ai:8000/analyze' => Http::response(self::MOU)]);
    }

    private const MOU = [
        'kind' => 'pdf', 'pages' => 1, 'readable' => true, 'ocr_pages' => [], 'rows' => null, 'warnings' => [],
        'text' => "VIỆN CÔNG NGHỆ THÔNG TIN\nHà Nội, ngày 5 tháng 1 năm 2026\nBIÊN BẢN GHI NHỚ HỢP TÁC\nViện Công nghệ thông tin và Công ty X hợp tác nghiên cứu.",
        'stamps' => [['page' => 1, 'x' => 0.6, 'y' => 0.8, 'r' => 0.09, 'score' => 0.3]],
        'signatures' => [['page' => 1, 'method' => 'blue_ink']], 'signature_blocks' => [],
    ];

    /** Chức năng basic: chỉ 2 câu trả lời Có (hợp tác có tệp, hướng mới chỉ có mô tả), còn lại Không */
    private function submit(): array
    {
        $answers = [];
        foreach ($this->definition['functions']['basic']['groups'] as $group) {
            foreach ($group['criteria'] as $q) {
                $answers['basic'][$q['id']] = [
                    'yes' => in_array($q['id'], ['basic_collaboration', 'basic_new_direction'], true) ? '1' : '0',
                    'inputs' => collect($q['inputs'] ?? [])->mapWithKeys(fn ($i) => [$i['name'] => 0])->all(),
                    'note' => 'Ghi chú',
                    'evidence_text' => $q['id'] === 'basic_collaboration' ? '' : 'Quyết định số 123',
                ];
            }
        }

        return $this->post('/api/v1/evaluations', [
            'organization_name' => 'Viện Công nghệ thông tin',
            'functions' => ['basic'],
            'weights' => ['basic' => 100],
            'answers' => $answers,
            'evidence' => ['basic' => ['basic_collaboration' => [UploadedFile::fake()->create('MOU.pdf', 50, 'application/pdf')]]],
        ], ['Accept' => 'application/json'])->assertCreated()->json();
    }

    private function detail(array $evaluation, string $qid): array
    {
        return collect($evaluation['result']['functions']['basic']['details'])->firstWhere('question_id', $qid);
    }

    public function test_files_are_read_and_score_follows_evidence(): void
    {
        $this->actingAsRole(['editor']);
        CriteriaVersion::create(['version' => 'v1', 'definition' => $this->definition, 'is_active' => true]);
        $this->fakeReader();

        $id = $this->submit()['id'];
        $e = $this->getJson("/api/v1/evaluations/$id")->assertOk()->json();

        // Có tệp đạt => giữ 5 điểm; chỉ có mô tả => 0 điểm
        $collab = $this->detail($e, 'basic_collaboration');
        $this->assertSame(['pass', 5], [$collab['evidence']['status'], $collab['score']]);
        $this->assertStringContainsString('«MOU.pdf» là Biên Bản Ghi Nhớ', $collab['explanation']);
        $direction = $this->detail($e, 'basic_new_direction');
        $this->assertSame(['fail', 0, 2], [$direction['evidence']['status'], $direction['score'], $direction['declared_score']]);
        $this->assertStringContainsString('mô tả không nhắc tới nội dung của tiêu chí', $direction['explanation']);

        $this->assertEquals(5, $e['total_score']);
        $this->assertEquals(['pass' => 1, 'review' => 0, 'fail' => 1, 'pending' => 0], $e['evidence']);
        $this->assertSame('done', $e['attachments'][0]['analysis_status']);
        Http::assertSentCount(1);
        // Gửi kèm nội dung tiêu chí để mô hình AI (nếu bật) biết cần kiểm chứng điều gì
        Http::assertSent(fn ($r) => collect($r->data())->contains(fn ($part) => $part['name'] === 'context'
            && str_contains($part['contents'], '"unit":"Viện Công nghệ thông tin"') && str_contains($part['contents'], 'hợp tác')));
    }

    public function test_reviewer_overrides_verdict_and_score_is_recomputed(): void
    {
        $this->actingAsRole(['editor']);
        CriteriaVersion::create(['version' => 'v1', 'definition' => $this->definition, 'is_active' => true]);
        $this->fakeReader();
        $id = $this->submit()['id'];

        $res = $this->putJson("/api/v1/evaluations/$id/evidence", [
            'question_key' => 'basic.basic_collaboration', 'verdict' => 'fail', 'reason' => 'Biên bản đã hết hiệu lực',
        ])->assertOk();
        $this->assertEquals(0, $res->json('total_score'));
        $this->assertStringContainsString('Lãnh đạo kết luận minh chứng không đạt: Biên bản đã hết hiệu lực', $this->detail($res->json(), 'basic_collaboration')['explanation']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'evidence.reviewed']);

        // Bỏ kết luận của lãnh đạo => quay về kết luận tự động
        $this->putJson("/api/v1/evaluations/$id/evidence", ['question_key' => 'basic.basic_collaboration', 'verdict' => null])
            ->assertOk()->assertJsonPath('total_score', 5);

        $this->putJson("/api/v1/evaluations/$id/evidence", ['question_key' => 'basic.basic_collaboration', 'verdict' => 'pass'])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');

        $this->actingAsRole(['viewer']);
        $this->putJson("/api/v1/evaluations/$id/evidence", ['question_key' => 'basic.basic_collaboration', 'verdict' => 'pass', 'reason' => 'ok ok'])
            ->assertForbidden();
    }

    public function test_dataset_command_exports_reviewer_labels_and_accuracy(): void
    {
        $this->actingAsRole(['editor']);
        CriteriaVersion::create(['version' => 'v1', 'definition' => $this->definition, 'is_active' => true]);
        $this->fakeReader();
        $id = $this->submit()['id'];
        // Hệ thống: Đạt; lãnh đạo: Không đạt => 0/1 khớp
        $this->putJson("/api/v1/evaluations/$id/evidence", ['question_key' => 'basic.basic_collaboration', 'verdict' => 'fail', 'reason' => 'MOU đã hết hạn'])->assertOk();

        $this->artisan('evidence:dataset', ['--out' => 'storage/framework/testing/dataset.jsonl'])
            ->expectsOutputToContain('Đã xuất 1 dòng')
            ->expectsOutputToContain('khớp lãnh đạo: 0/1')
            ->assertSuccessful();
        $row = json_decode(trim(file_get_contents(base_path('storage/framework/testing/dataset.jsonl'))), true);
        $this->assertSame(['basic.basic_collaboration', 'pass', 'fail', 'MOU đã hết hạn', 'MOU.pdf'],
            [$row['question_key'], $row['auto'], $row['label'], $row['label_reason'], $row['files'][0]['name']]);
        @unlink(base_path('storage/framework/testing/dataset.jsonl'));
    }

    public function test_reader_outage_needs_review_then_recheck_reads_again(): void
    {
        $this->actingAsRole(['editor']);
        CriteriaVersion::create(['version' => 'v1', 'definition' => $this->definition, 'is_active' => true]);
        Http::fake(['evidence-ai:8000/analyze' => Http::sequence()->push('down', 503)->push(self::MOU)]);
        $id = $this->submit()['id'];

        $collab = $this->detail(Evaluation::find($id)->only('result'), 'basic_collaboration');
        $this->assertSame(['review', ['analysis_failed'], 5], [$collab['evidence']['status'], $collab['evidence']['reasons'], $collab['score']]);

        $this->postJson("/api/v1/evaluations/$id/evidence/recheck")->assertOk();
        $this->assertSame('pass', $this->detail($this->getJson("/api/v1/evaluations/$id")->json(), 'basic_collaboration')['evidence']['status']);
    }

    public function test_disabled_check_keeps_old_scoring(): void
    {
        config(['evidence.enabled' => false]);
        $this->actingAsRole(['editor']);
        CriteriaVersion::create(['version' => 'v1', 'definition' => $this->definition, 'is_active' => true]);
        Http::fake();

        $e = $this->submit();

        $this->assertEquals(7, $e['total_score']);
        $this->assertNull($e['evidence']);
        Http::assertNothingSent();
    }
}
