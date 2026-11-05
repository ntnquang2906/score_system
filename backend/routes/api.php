<?php

use App\Http\Controllers\Api\V1\AttachmentController;
use App\Http\Controllers\Api\V1\CriteriaController;
use App\Http\Controllers\Api\V1\EvaluationController;
use App\Http\Controllers\Api\V1\EvidenceController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\TempAccountController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('health', HealthController::class);
    // TẠM THỜI khi chưa có SSO (xem TempAccountController)
    Route::post('temp-accounts', TempAccountController::class)->middleware('throttle:10,1');

    Route::middleware('auth:api')->group(function () {
        Route::get('me', MeController::class);

        Route::get('criteria/active', [CriteriaController::class, 'active']);
        Route::get('criteria/{criteria}', [CriteriaController::class, 'show'])->whereNumber('criteria');

        Route::get('evaluations', [EvaluationController::class, 'index']);
        Route::get('evaluations/summary', [EvaluationController::class, 'summaryData']);
        Route::get('evaluations/summary/export', [EvaluationController::class, 'summary']);
        Route::post('evaluations', [EvaluationController::class, 'store'])->middleware('throttle:20,1');
        Route::get('evaluations/{evaluation}', [EvaluationController::class, 'show'])->whereNumber('evaluation');
        Route::put('evaluations/{evaluation}', [EvaluationController::class, 'update'])->whereNumber('evaluation');
        Route::get('evaluations/{evaluation}/export', [EvaluationController::class, 'export'])->whereNumber('evaluation');
        Route::put('evaluations/{evaluation}/evidence', [EvidenceController::class, 'review'])->whereNumber('evaluation');
        Route::post('evaluations/{evaluation}/evidence/recheck', [EvidenceController::class, 'recheck'])->whereNumber('evaluation');

        Route::get('attachments/{attachment}/download', [AttachmentController::class, 'download']);
    });
});
