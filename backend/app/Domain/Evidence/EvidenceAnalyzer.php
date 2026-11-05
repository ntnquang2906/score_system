<?php

namespace App\Domain\Evidence;

use App\Models\Attachment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** Gửi 1 tệp minh chứng (từ S3) sang dịch vụ đọc tệp nội bộ (docker/evidence-ai) và lưu dữ kiện trả về. */
final class EvidenceAnalyzer
{
    public function analyze(Attachment $attachment): void
    {
        try {
            $response = Http::timeout(config('evidence.analyzer_timeout'))
                ->attach('file', Storage::disk('s3')->readStream($attachment->object_key), $attachment->original_name)
                ->post(rtrim(config('evidence.analyzer_url'), '/').'/analyze')
                ->throw();

            $attachment->update(['analysis_status' => 'done', 'analysis' => $response->json(), 'analyzed_at' => now()]);
        } catch (Throwable $e) {
            Log::warning('Không đọc được tệp minh chứng', ['attachment' => $attachment->id, 'error' => $e->getMessage()]);
            $attachment->update(['analysis_status' => 'failed', 'analysis' => ['error' => class_basename($e)], 'analyzed_at' => now()]);
        }
    }
}
