<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Tự kiểm tra minh chứng:
//  - attachments.analysis: dữ kiện đọc được từ tệp (chữ, con dấu, chữ ký...), đọc 1 lần rồi dùng lại
//  - evidence_reviews: kết luận lãnh đạo đặt lại cho từng tiêu chí của 1 bài
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->string('analysis_status', 20)->nullable();   // pending | done | failed
            $table->jsonb('analysis')->nullable();
            $table->timestamp('analyzed_at')->nullable();
        });

        Schema::create('evidence_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('evaluation_id')->constrained()->cascadeOnDelete();
            $table->string('question_key');                      // chức_năng.mã_câu
            $table->string('verdict', 10);                       // pass | review | fail
            $table->text('reason');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('files_signature', 64);               // bộ tệp lúc lãnh đạo xem; đổi tệp => kết luận cũ hết hiệu lực
            $table->timestamps();

            $table->unique(['evaluation_id', 'question_key']);
        });

        DB::statement('ALTER TABLE evidence_reviews ENABLE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON evidence_reviews
            USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint)
            WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint)
        SQL);

        // Bổ sung quy tắc kiểm tra minh chứng ("evidence") vào các bộ tiêu chí đã lưu, theo mã câu hỏi
        $rules = [];
        $file = json_decode(file_get_contents(database_path('data/criteria_v1.json')), true);
        foreach ($file['functions'] as $func) {
            foreach ($func['groups'] as $group) {
                foreach ($group['criteria'] as $q) {
                    $rules[$q['id']] = $q['evidence'];
                }
            }
        }
        foreach (DB::table('criteria_versions')->get() as $row) {
            $definition = json_decode($row->definition, true);
            foreach ($definition['functions'] as &$func) {
                foreach ($func['groups'] as &$group) {
                    foreach ($group['criteria'] as &$q) {
                        $q['evidence'] ??= $rules[$q['id']] ?? null;
                    }
                }
            }
            unset($func, $group, $q);
            DB::table('criteria_versions')->where('id', $row->id)->update(['definition' => json_encode($definition, JSON_UNESCAPED_UNICODE)]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('evidence_reviews');
        Schema::table('attachments', fn (Blueprint $table) => $table->dropColumn(['analysis_status', 'analysis', 'analyzed_at']));
    }
};
