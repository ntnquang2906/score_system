<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    /** Tải tệp minh chứng (đi qua API để kiểm tra quyền; kho S3 không mở ra ngoài). */
    public function download(Attachment $attachment): StreamedResponse
    {
        Gate::authorize('view', $attachment->evaluation);

        return Storage::disk('s3')->download($attachment->object_key, $attachment->original_name);
    }
}
