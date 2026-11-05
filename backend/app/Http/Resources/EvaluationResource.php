<?php

namespace App\Http\Resources;

use App\Models\Evaluation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Evaluation */
class EvaluationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization' => [
                'id' => $this->organization_id,
                'name' => $this->organization?->name,
                'submitted_name' => $this->organization_name,
                'submissions_count' => $this->organization?->evaluations_count,
            ],
            'functions' => $this->functions,
            'total_score' => (float) $this->total_score,
            'grade' => $this->grade,
            // Số tiêu chí theo kết luận minh chứng: pass / review / fail / pending (null = không kiểm tra)
            'evidence' => $this->result['evidence'] ?? null,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'submitted_by' => $this->whenLoaded('submitter', fn () => $this->submitter?->full_name),
            'imported_from' => $this->legacy_file,
            'criteria_version_id' => $this->criteria_version_id,
            // full: sửa toàn bộ và chấm lại; organization_only: bài nhập từ hệ cũ, chỉ đổi tên đơn vị
            'edit_mode' => $this->isLegacy() ? 'organization_only' : 'full',
            'can_edit' => $request->user()?->can('update', $this->resource) ?? false,
            // Chi tiết chỉ trả về khi xem 1 bài
            'result' => $this->when($request->route('evaluation') !== null, $this->result),
            'answers' => $this->when($request->route('evaluation') !== null, $this->answers),
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),
        ];
    }
}
