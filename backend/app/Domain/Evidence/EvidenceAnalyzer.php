<?php

namespace App\Domain\Evidence;

use App\Models\Attachment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Gửi 1 tệp minh chứng (từ S3) sang dịch vụ đọc tệp nội bộ (docker/evidence-ai) và lưu dữ kiện trả về.
 * $context (nội dung tiêu chí, loại văn bản, đơn vị) để mô hình AI (nếu bật) biết cần kiểm chứng điều gì.
 */
final class EvidenceAnalyzer
{
    public function analyze(Attachment $attachment, array $context = []): void
    {
        try {
            $response = Http::timeout(config('evidence.analyzer_timeout'))
                ->attach('file', Storage::disk('s3')->readStream($attachment->object_key), $attachment->original_name)
                ->post(rtrim(config('evidence.analyzer_url'), '/').'/analyze', $context ? ['context' => json_encode($context, JSON_UNESCAPED_UNICODE)] : [])
                ->throw();

            $attachment->update(['analysis_status' => 'done', 'analysis' => $response->json(), 'analyzed_at' => now()]);
        } catch (Throwable $e) {
            Log::warning('Không đọc được tệp minh chứng', ['attachment' => $attachment->id, 'error' => $e->getMessage()]);
            $attachment->update(['analysis_status' => 'failed', 'analysis' => ['error' => class_basename($e)], 'analyzed_at' => now()]);
        }
    }
}
