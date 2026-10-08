<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tổ chức KH&CN được đánh giá (thay cho "khoá đơn vị" chuẩn hoá từ tên file cũ).
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('normalized_key');
            $table->timestamps();

            $table->unique(['tenant_id', 'normalized_key']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('unit_name')
                ->constrained()->nullOnDelete();
        });

        // Bộ tiêu chí có phiên bản (nội dung criteria.json). Bài đánh giá trỏ tới đúng phiên bản đã dùng.
        Schema::create('criteria_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('version', 50);
            $table->jsonb('definition');
            $table->boolean('is_active')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'version']);
        });

        Schema::create('evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('criteria_version_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('organization_name');           // tên nguyên văn người dùng nhập
            $table->jsonb('functions')->default('[]');      // basic | applied | tech | policy
            $table->jsonb('answers')->default('{}');
            $table->jsonb('result')->default('{}');         // chi tiết điểm từng câu, Đt1..Đt4
            $table->decimal('total_score', 8, 2)->nullable();
            $table->string('grade', 50)->nullable();
            $table->string('legacy_file')->nullable();      // tên file .tsv gốc khi import từ hệ cũ
            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->index(['tenant_id', 'organization_id', 'submitted_at']);
            $table->unique(['tenant_id', 'legacy_file']);
        });

        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('evaluation_id')->constrained()->cascadeOnDelete();
            $table->string('question_key')->nullable();
            $table->string('original_name');
            $table->string('object_key');                   // đường dẫn trong bucket S3
            $table->string('mime_type', 150)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 100);
            $table->string('subject_type', 100)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->jsonb('changes')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('evaluations');
        Schema::dropIfExists('criteria_versions');
        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('organization_id'));
        Schema::dropIfExists('organizations');
    }
};
