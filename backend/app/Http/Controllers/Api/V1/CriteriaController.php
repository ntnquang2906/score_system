<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CriteriaVersion;
use Illuminate\Http\JsonResponse;

class CriteriaController extends Controller
{
    /** Bộ tiêu chí đang áp dụng (để dựng form đánh giá). */
    public function active(): JsonResponse
    {
        $criteria = CriteriaVersion::where('is_active', true)->latest('id')->firstOrFail();

        return response()->json([
            'id' => $criteria->id,
            'version' => $criteria->version,
            'definition' => $criteria->definition,
        ]);
    }

    /** 1 phiên bản bộ tiêu chí cụ thể (form sửa bài dùng đúng phiên bản bài đó đã chấm) */
    public function show(CriteriaVersion $criteria): JsonResponse
    {
        return response()->json([
            'id' => $criteria->id,
            'version' => $criteria->version,
            'definition' => $criteria->definition,
        ]);
    }
}
