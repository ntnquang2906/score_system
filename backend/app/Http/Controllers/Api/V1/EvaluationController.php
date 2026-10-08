<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Evaluation\EvaluationExport;
use App\Domain\Evaluation\SubmitEvaluation;
use App\Http\Controllers\Controller;
use App\Http\Resources\EvaluationResource;
use App\Models\Evaluation;
use App\Support\TenantContext;
use App\Support\Vietnamese;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class EvaluationController extends Controller
{
    /**
     * Danh sách bài đánh giá (giống dashboard cũ):
     *  - Lãnh đạo/quản trị: mỗi đơn vị chỉ hiện LẦN NỘP CUỐI; mọi lần nộp vẫn lưu trong DB.
     *    Xem toàn bộ lịch sử 1 đơn vị: ?organization_id=
     *  - Tài khoản đơn vị: thấy mọi lần nộp của đơn vị mình.
     * Lọc thêm: ?q= (tên tổ chức), ?grade=A
     */
    public function index(Request $request, TenantContext $tenant): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Evaluation::class);
        $user = $request->user();

        $scope = Evaluation::query();
        if (! $user->canSeeAllEvaluations()) {
            $scope->whereHas('organization', fn ($q) => $q->where('normalized_key', $user->unitKey()));
        }

        $latestOnly = $user->canSeeAllEvaluations() && ! $request->filled('organization_id');
        $latestIds = self::latestIdsPerOrganization($tenant);

        // Số liệu tổng hợp (theo phạm vi được xem, trước khi lọc tìm kiếm)
        $stats = (clone $scope)->toBase()
            ->selectRaw('count(*) as total, count(distinct organization_id) as organizations')
            ->first();
        $average = (clone $scope)->when($latestOnly, fn ($q) => $q->whereIn('id', $latestIds))->avg('total_score');

        $query = (clone $scope)
            ->with(['organization' => fn ($q) => $q->withCount('evaluations'), 'submitter'])
            ->latest('submitted_at')->orderByDesc('id')
            ->when($latestOnly, fn ($q) => $q->whereIn('id', $latestIds))
            ->when($request->filled('organization_id'), fn ($q) => $q->where('organization_id', $request->integer('organization_id')))
            ->when($request->filled('grade'), fn ($q) => $q->where('grade', $request->string('grade')))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w
                ->where('organization_name', 'ilike', '%'.$request->string('q').'%')
                ->orWhereHas('organization', fn ($o) => $o->where('name', 'ilike', '%'.$request->string('q').'%'))));

        return EvaluationResource::collection($query->paginate(min($request->integer('per_page', 20), 100)))
            ->additional([
                'latest_only' => $latestOnly,
                'stats' => [
                    'total' => (int) $stats->total,
                    'organizations' => (int) $stats->organizations,
                    'average' => $average !== null ? round((float) $average, 2) : null,
                ],
            ]);
    }

    public function show(Evaluation $evaluation): EvaluationResource
    {
        Gate::authorize('view', $evaluation);

        return new EvaluationResource($evaluation->load('organization', 'submitter', 'attachments'));
    }

    /**
     * Nộp bài. Gửi multipart/form-data (khi có tệp) hoặc JSON:
     *   organization_name, functions[], weights[func], answers[func][qid][yes|inputs|note|evidence_text],
     *   evidence[func][qid][] (tệp minh chứng)
     */
    public function store(Request $request, SubmitEvaluation $submit): EvaluationResource
    {
        Gate::authorize('create', Evaluation::class);

        $evaluation = $submit->handle(
            $request->user(),
            $request->except('evidence'),
            $request->file('evidence', []),
            $request->ip(),
            $request->userAgent(),
        );

        return new EvaluationResource($evaluation->load('organization', 'submitter', 'attachments'));
    }

    /**
     * Sửa bài (admin/editor). Gửi multipart (POST kèm _method=PUT) hoặc JSON, cùng dữ liệu như khi nộp,
     * thêm remove_attachments[] = id tệp cần xoá. Bài nhập từ hệ cũ: chỉ nhận organization_name.
     */
    public function update(Request $request, Evaluation $evaluation, SubmitEvaluation $submit): EvaluationResource
    {
        Gate::authorize('update', $evaluation);

        $evaluation = $submit->update(
            $request->user(),
            $evaluation,
            $request->except('evidence', 'remove_attachments', '_method'),
            $request->file('evidence', []),
            array_map('intval', (array) $request->input('remove_attachments', [])),
            $request->ip(),
            $request->userAgent(),
        );

        return new EvaluationResource($evaluation->fresh()->load('organization', 'submitter', 'attachments'));
    }

    /** Tải kết quả 1 bài dạng Excel (.xlsx), cùng cột với file kết quả của hệ cũ. */
    public function export(Evaluation $evaluation, EvaluationExport $export): Response
    {
        Gate::authorize('view', $evaluation);
        $evaluation->load('organization', 'attachments');

        return response($export->xlsx($evaluation), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$export->filename($evaluation).'"',
        ]);
    }

    /**
     * Dữ liệu file tổng hợp để XEM trên web (cùng nội dung với file Excel tổng hợp).
     * Chỉ lãnh đạo/quản trị.
     */
    public function summaryData(EvaluationExport $export, TenantContext $tenant): JsonResponse
    {
        abort_unless(request()->user()->canSeeAllEvaluations(), 403, 'Chỉ lãnh đạo/quản trị được xem file tổng hợp.');

        $evaluations = $this->latestEvaluations($tenant);

        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'organizations' => $evaluations->map(fn (Evaluation $e) => [
                'evaluation_id' => $e->id,
                'organization' => $e->organization?->name,
                'submitted_name' => $e->organization_name,
                'submitted_at' => $e->submitted_at->toIso8601String(),
                'total_score' => (float) $e->total_score,
                'grade' => $e->grade,
                'functions' => collect($e->result['functions'] ?? [])->map(fn ($f) => [
                    'name' => $f['name'],
                    'name_en' => $f['name_en'] ?? $f['name'],
                    'weight' => $f['weight'],
                    'dt1' => $f['dt1'], 'dt2' => $f['dt2'], 'dt3' => $f['dt3'], 'dt4' => $f['dt4'],
                    'dt' => $f['dt'], 'weighted' => $f['weighted'],
                ]),
            ])->values(),
            'columns' => EvaluationExport::HEADER,
            'rows' => $evaluations->flatMap(fn (Evaluation $e) => array_map(
                fn ($row) => ['evaluation_id' => $e->id, 'cells' => $row],
                $export->rows($e),
            ))->values(),
        ]);
    }

    /**
     * File tổng hợp (như results.tsv cũ): lần nộp cuối của mọi đơn vị trong 1 file Excel.
     * Chỉ lãnh đạo/quản trị (admin, editor, viewer).
     */
    public function summary(EvaluationExport $export, TenantContext $tenant): Response
    {
        abort_unless(request()->user()->canSeeAllEvaluations(), 403, 'Chỉ lãnh đạo/quản trị được tải file tổng hợp.');

        return response($export->summaryXlsx($this->latestEvaluations($tenant)), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="Tong_hop_ket_qua_'.now('Asia/Ho_Chi_Minh')->format('Ymd_His').'.xlsx"',
        ]);
    }

    /** Lần nộp cuối của mọi đơn vị, xếp theo tên đơn vị */
    private function latestEvaluations(TenantContext $tenant)
    {
        return Evaluation::with('organization', 'attachments')
            ->whereIn('id', self::latestIdsPerOrganization($tenant))
            ->get()
            // sắp theo tên tiếng Việt: bỏ dấu trước khi so (để "Đ" đứng cạnh "D", không bị xếp cuối)
            ->sortBy(fn (Evaluation $e) => strtolower(Vietnamese::stripDiacritics($e->organization?->name ?? $e->organization_name)))
            ->values();
    }

    /** Subquery: id lần nộp mới nhất của mỗi đơn vị */
    private static function latestIdsPerOrganization(TenantContext $tenant): \Closure
    {
        return fn ($q) => $q->from('evaluations')
            ->where('tenant_id', $tenant->id())
            ->selectRaw('distinct on (organization_id) id')
            ->orderBy('organization_id')->orderByDesc('submitted_at')->orderByDesc('id');
    }
}
