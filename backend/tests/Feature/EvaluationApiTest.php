<?php

namespace Tests\Feature;

use App\Domain\Scoring\EvaluationScorer;
use App\Models\CriteriaVersion;
use App\Models\Evaluation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EvaluationApiTest extends TestCase
{
    use RefreshDatabase;

    private array $definition;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
        $this->definition = json_decode(file_get_contents(database_path('data/criteria_v1.json')), true);
    }

    private function activateCriteria(): void
    {
        CriteriaVersion::create(['version' => 'v1', 'definition' => $this->definition, 'is_active' => true]);
    }

    /** Bài nộp hợp lệ cho chức năng "basic" (100%): trả lời Có, nhập số liệu, có mô tả minh chứng. */
    private function validPayload(array $override = []): array
    {
        $answers = [];
        foreach ($this->definition['functions']['basic']['groups'] as $group) {
            foreach ($group['criteria'] as $q) {
                $answers['basic'][$q['id']] = [
                    'yes' => '1',
                    'inputs' => collect($q['inputs'] ?? [])->mapWithKeys(fn ($i) => [$i['name'] => 3])->all(),
                    'note' => 'Ghi chú',
                    'evidence_text' => 'Quyết định số 123',
                ];
            }
        }

        return array_replace_recursive([
            'organization_name' => 'Viện Công nghệ thông tin - ĐHQGHN',
            'functions' => ['basic'],
            'weights' => ['basic' => 100],
            'answers' => $answers,
        ], $override);
    }

    public function test_requires_login(): void
    {
        $this->getJson('/api/v1/evaluations')->assertUnauthorized();
    }

    public function test_editor_submits_and_score_matches_scorer(): void
    {
        $this->actingAsRole(['editor']);
        $this->activateCriteria();
        $payload = $this->validPayload();

        $res = $this->postJson('/api/v1/evaluations', $payload)->assertCreated();

        $expected = (new EvaluationScorer)->score($this->definition, ['basic'], ['basic' => 100], $payload['answers']);
        $this->assertEquals(round($expected['total'], 2), $res->json('total_score'));
        $res->assertJsonPath('grade', $expected['grade'])
            ->assertJsonPath('organization.name', 'Viện Công nghệ thông tin');

        $this->assertDatabaseHas('organizations', ['normalized_key' => 'vien_cong_nghe_thong_tin']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'evaluation.submitted']);
    }

    public function test_unit_account_submits_for_its_own_unit_with_files(): void
    {
        $this->actingAsRole(['unit'], ['unit_name' => 'Trường Đại học Công nghệ']);
        $this->activateCriteria();
        $payload = $this->validPayload(['organization_name' => 'Đơn vị khác']);
        $firstQ = array_key_first($payload['answers']['basic']);
        $payload['answers']['basic'][$firstQ]['evidence_text'] = '';   // có tệp thì không cần mô tả

        $res = $this->post('/api/v1/evaluations', $payload + [
            'evidence' => ['basic' => [$firstQ => [UploadedFile::fake()->create('Quyết định.pdf', 100, 'application/pdf')]]],
        ], ['Accept' => 'application/json'])->assertCreated();

        $res->assertJsonPath('organization.name', 'Trường Đại học Công nghệ')   // bỏ qua tên gửi lên
            ->assertJsonCount(1, 'attachments')
            ->assertJsonPath('attachments.0.name', 'Quyết định.pdf');
        Storage::disk('s3')->assertExists(Evaluation::first()->attachments()->first()->object_key);
    }

    public function test_validation_messages_follow_old_form_rules(): void
    {
        $this->actingAsRole(['editor']);
        $this->activateCriteria();
        $payload = $this->validPayload(['weights' => ['basic' => 90]]);
        $firstQ = array_key_first($payload['answers']['basic']);
        $payload['answers']['basic'][$firstQ]['evidence_text'] = '';

        $this->postJson('/api/v1/evaluations', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(["answers.basic.$firstQ.evidence_text"]);

        $payload['answers']['basic'][$firstQ]['evidence_text'] = 'ok';
        $this->postJson('/api/v1/evaluations', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['weights']);

        $this->assertSame(0, Evaluation::count());
    }

    public function test_unit_only_sees_its_own_unit(): void
    {
        $this->actingAsRole(['editor']);
        $this->activateCriteria();
        $mine = $this->postJson('/api/v1/evaluations', $this->validPayload(['organization_name' => 'Trường ĐH Công nghệ, VNU']))->json('id');
        $other = $this->postJson('/api/v1/evaluations', $this->validPayload(['organization_name' => 'Trường ĐH Ngoại ngữ']))->json('id');

        $this->actingAsRole(['unit'], ['unit_name' => 'Trường ĐH Công nghệ']);
        $this->getJson('/api/v1/evaluations')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine);
        $this->getJson("/api/v1/evaluations/$mine")->assertOk()->assertJsonStructure(['result' => ['functions', 'total', 'grade']]);
        $this->getJson("/api/v1/evaluations/$other")->assertForbidden();

        $this->actingAsRole(['viewer']);
        $this->getJson('/api/v1/evaluations')->assertJsonCount(2, 'data')
            ->assertJsonPath('stats.total', 2)->assertJsonPath('stats.organizations', 2);
        $this->postJson('/api/v1/evaluations', $this->validPayload())->assertForbidden();
    }

    public function test_export_excel_has_legacy_columns(): void
    {
        $this->actingAsRole(['editor']);
        $this->activateCriteria();
        $id = $this->postJson('/api/v1/evaluations', $this->validPayload())->json('id');

        $res = $this->get("/api/v1/evaluations/$id/export")->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString('Vien_Cong_nghe_thong_tin.xlsx', $res->headers->get('Content-Disposition'));

        $tmp = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($tmp, $res->getContent());
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($tmp));
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertStringContainsString('Điểm quy đổi', $sheet);
        $this->assertStringContainsString('Mô tả: Quyết định số 123', $sheet);
        unlink($tmp);
    }

    public function test_list_shows_only_latest_submission_per_unit_but_keeps_history(): void
    {
        $this->actingAsRole(['editor']);
        $this->activateCriteria();
        $old = $this->postJson('/api/v1/evaluations', $this->validPayload(['organization_name' => 'Trường ĐH Công nghệ']))->json('id');
        $this->travel(1)->minutes();
        $new = $this->postJson('/api/v1/evaluations', $this->validPayload(['organization_name' => 'Truong DH Cong nghe - VNU']))->json('id');
        $this->travel(1)->minutes();
        $this->postJson('/api/v1/evaluations', $this->validPayload(['organization_name' => 'Trường ĐH Ngoại ngữ']));

        // Lãnh đạo: 2 đơn vị -> 2 dòng, ĐH Công nghệ hiện lần nộp mới nhất, kèm số lần nộp
        $res = $this->getJson('/api/v1/evaluations')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('latest_only', true)
            ->assertJsonPath('stats.total', 3)->assertJsonPath('stats.organizations', 2);
        $cn = collect($res->json('data'))->firstWhere('organization.name', 'Trường ĐH Công nghệ');
        $this->assertSame($new, $cn['id']);
        $this->assertSame(2, $cn['organization']['submissions_count']);

        // Lịch sử 1 đơn vị: đủ mọi lần nộp, mới nhất trước
        $this->getJson('/api/v1/evaluations?organization_id='.$cn['organization']['id'])
            ->assertJsonPath('latest_only', false)
            ->assertJsonPath('data.0.id', $new)->assertJsonPath('data.1.id', $old);

        // Bài cũ vẫn xem được
        $this->getJson("/api/v1/evaluations/$old")->assertOk();

        // Tài khoản đơn vị: thấy mọi lần nộp của đơn vị mình
        $this->actingAsRole(['unit'], ['unit_name' => 'Trường ĐH Công nghệ']);
        $this->getJson('/api/v1/evaluations')->assertJsonCount(2, 'data')->assertJsonPath('latest_only', false);
    }

    public function test_summary_file_has_latest_of_every_unit_and_is_leaders_only(): void
    {
        $this->actingAsRole(['editor']);
        $this->activateCriteria();
        $this->postJson('/api/v1/evaluations', $this->validPayload(['organization_name' => 'Đơn vị A cũ']));
        $this->travel(1)->minutes();
        $this->postJson('/api/v1/evaluations', $this->validPayload(['organization_name' => 'Trường ĐH Ngoại ngữ']));

        $this->actingAsRole(['viewer']);
        $res = $this->get('/api/v1/evaluations/summary/export')->assertOk();
        $this->assertStringContainsString('Tong_hop_ket_qua_', $res->headers->get('Content-Disposition'));
        $tmp = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($tmp, $res->getContent());
        $zip = new \ZipArchive;
        $zip->open($tmp);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        unlink($tmp);
        $this->assertStringContainsString('Đơn vị A cũ', $sheet);
        $this->assertStringContainsString('Trường ĐH Ngoại ngữ', $sheet);
        $questions = collect($this->definition['functions']['basic']['groups'])->sum(fn ($g) => count($g['criteria']));
        $this->assertSame(1 + 2 * $questions, substr_count($sheet, '<row '));   // tiêu đề + 2 đơn vị

        // Xem trên web: cùng nội dung, mỗi đơn vị 1 dòng tổng + các dòng chi tiết
        $data = $this->getJson('/api/v1/evaluations/summary')->assertOk()
            ->assertJsonCount(2, 'organizations')
            ->assertJsonCount(2 * $questions, 'rows')
            ->assertJsonPath('columns.9', 'Điểm quy đổi')
            ->json();
        $this->assertSame('Trường ĐH Ngoại ngữ', $data['organizations'][1]['organization']);
        $this->assertArrayHasKey('basic', $data['organizations'][0]['functions']);

        $this->actingAsRole(['unit'], ['unit_name' => 'Trường ĐH Ngoại ngữ']);
        $this->get('/api/v1/evaluations/summary/export')->assertForbidden();
        $this->getJson('/api/v1/evaluations/summary')->assertForbidden();
    }

    public function test_editor_can_edit_submission_and_score_is_recalculated(): void
    {
        $this->actingAsRole(['unit'], ['unit_name' => 'Trường ĐH Công nghệ']);
        $this->activateCriteria();
        $payload = $this->validPayload();
        $firstQ = array_key_first($payload['answers']['basic']);
        $payload['answers']['basic'][$firstQ]['evidence_text'] = '';
        $created = $this->post('/api/v1/evaluations', $payload + [
            'evidence' => ['basic' => [$firstQ => [UploadedFile::fake()->create('cu.pdf', 10)]]],
        ], ['Accept' => 'application/json'])->assertCreated()->json();
        $id = $created['id'];
        $this->assertSame('full', $created['edit_mode']);
        $this->assertFalse($created['can_edit']);                          // đơn vị không được sửa
        $this->putJson("/api/v1/evaluations/$id", $payload)->assertForbidden();

        // editor sửa: đổi mọi câu thành "Không" -> điểm phải về 0, đổi tên đơn vị, xoá tệp cũ + thêm tệp mới
        $this->actingAsRole(['editor']);
        $edit = $this->validPayload(['organization_name' => 'Trường ĐH Ngoại ngữ']);
        foreach ($edit['answers']['basic'] as $q => $a) {
            $edit['answers']['basic'][$q]['yes'] = '0';
        }
        $edit['answers']['basic'][$firstQ]['evidence_text'] = '';
        $oldFile = $created['attachments'][0]['id'];
        $oldKey = \App\Models\Attachment::find($oldFile)->object_key;

        // xoá tệp cũ mà không thêm tệp/mô tả mới -> thiếu minh chứng
        $this->putJson("/api/v1/evaluations/$id", $edit + ['remove_attachments' => [$oldFile]])
            ->assertUnprocessable()->assertJsonValidationErrors(["answers.basic.$firstQ.evidence_text"]);

        $res = $this->post("/api/v1/evaluations/$id", $edit + [
            '_method' => 'PUT',
            'remove_attachments' => [$oldFile],
            'evidence' => ['basic' => [$firstQ => [UploadedFile::fake()->create('moi.pdf', 10)]]],
        ], ['Accept' => 'application/json'])->assertOk();

        $res->assertJsonPath('grade', 'D')->assertJsonPath('organization.name', 'Trường ĐH Ngoại ngữ')
            ->assertJsonCount(1, 'attachments')->assertJsonPath('attachments.0.name', 'moi.pdf');
        $this->assertEquals(0, $res->json('total_score'));
        Storage::disk('s3')->assertMissing($oldKey);
        $log = \App\Models\AuditLog::where('action', 'evaluation.updated')->latest('id')->first();
        $this->assertSame('Trường ĐH Công nghệ', $log->changes['before']['organization']);
        $this->assertSame(['cu.pdf'], $log->changes['before']['removed_files']);
        $this->assertSame('D', $log->changes['after']['grade']);
    }

    public function test_legacy_imported_submission_only_allows_renaming_unit(): void
    {
        $this->actingAsRole(['admin', 'editor']);
        $org = \App\Models\Organization::create(['name' => 'Nguyễn Văn A', 'normalized_key' => 'nguyen_van_a']);
        $e = Evaluation::create([
            'organization_id' => $org->id, 'organization_name' => 'Nguyễn Văn A', 'functions' => ['basic'],
            'answers' => ['legacy' => true, 'answers' => []], 'result' => ['functions' => []],
            'total_score' => 50, 'grade' => 'C', 'legacy_file' => 'x.tsv', 'submitted_at' => now(),
        ]);

        $this->getJson("/api/v1/evaluations/{$e->id}")->assertJsonPath('edit_mode', 'organization_only')->assertJsonPath('can_edit', true);
        $this->putJson("/api/v1/evaluations/{$e->id}", ['organization_name' => 'Viện Công nghệ thông tin'])
            ->assertOk()
            ->assertJsonPath('organization.name', 'Viện Công nghệ thông tin')
            ->assertJsonPath('total_score', 50);
    }

    public function test_researcher_has_no_access_to_evaluations(): void
    {
        $this->actingAsRole(['researcher']);
        $this->getJson('/api/v1/evaluations')->assertForbidden();
    }

    public function test_other_tenant_cannot_see_evaluation(): void
    {
        $this->actingAsRole(['editor']);
        $this->activateCriteria();
        $id = $this->postJson('/api/v1/evaluations', $this->validPayload())->json('id');

        $this->actingAsRole(['editor'], [], tenant: 'other-university');
        $this->getJson("/api/v1/evaluations/$id")->assertNotFound();
        $this->getJson('/api/v1/evaluations')->assertJsonCount(0, 'data');
    }
}
