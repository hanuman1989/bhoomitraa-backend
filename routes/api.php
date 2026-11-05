<?php

use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\CropCategoryController;
use App\Http\Controllers\Admin\CropController;
use App\Http\Controllers\Admin\CropStageController;
use App\Http\Controllers\Admin\CropStageTimelineController;
use App\Http\Controllers\Admin\CropVarietyController;
use App\Http\Controllers\Admin\KnowledgeSourceAttachmentController;
use App\Http\Controllers\Admin\KnowledgeSourceController;
use App\Http\Controllers\Admin\KnowledgeSourceReviewController;
use App\Http\Controllers\Admin\RegionController;
use App\Http\Controllers\Admin\SeasonController;
use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->name('admin.')->group(function (): void {
    Route::post('login', [AdminAuthController::class, 'login'])->middleware('throttle:login')->name('login');

    Route::middleware(['auth:admin', 'abilities:admin', 'admin.active'])->group(function (): void {
        Route::get('me', [AdminAuthController::class, 'me'])->name('me');
        Route::post('logout', [AdminAuthController::class, 'logout'])->name('logout');
        Route::patch('admin-users/{admin_user}/password', [AdminUserController::class, 'updatePassword'])
            ->middleware('can:manage-admin-users')->name('admin-users.update-password');
        Route::apiResource('admin-users', AdminUserController::class)->parameters(['admin-users' => 'admin_user'])->middleware('can:manage-admin-users');
        Route::apiResource('categories', CategoryController::class)->middleware('can:manage-categories');
        Route::apiResource('regions', RegionController::class)->middleware('can:manage-regions');

        Route::middleware('can:manage-seasons')->group(function (): void {
            Route::apiResource('seasons', SeasonController::class);
            Route::patch('seasons/{season}/status', [SeasonController::class, 'updateStatus'])->name('seasons.status');
        });

        Route::middleware('can:manage-crops')->group(function (): void {
            Route::apiResource('crops', CropController::class);
            Route::patch('crops/{crop}/status', [CropController::class, 'updateStatus'])->name('crops.status');
            Route::apiResource('crop-varieties', CropVarietyController::class)
                ->parameters(['crop-varieties' => 'cropVariety']);
            Route::patch('crop-varieties/{cropVariety}/status', [CropVarietyController::class, 'updateStatus'])
                ->name('crop-varieties.status');
            Route::apiResource('crop-stages', CropStageController::class)->parameters(['crop-stages' => 'cropStage']);
            Route::patch('crop-stages/{cropStage}/status', [CropStageController::class, 'updateStatus'])
                ->name('crop-stages.status');
            Route::apiResource('crop-stage-timelines', CropStageTimelineController::class)
                ->parameters(['crop-stage-timelines' => 'cropStageTimeline']);
            Route::patch('crop-stage-timelines/{cropStageTimeline}/status', [CropStageTimelineController::class, 'updateStatus'])
                ->name('crop-stage-timelines.status');
        });

        Route::middleware('can:manage-crop-categories')->group(function (): void {
            Route::apiResource('crop-categories', CropCategoryController::class)
                ->parameters(['crop-categories' => 'cropCategory']);
            Route::patch('crop-categories/{cropCategory}/status', [CropCategoryController::class, 'updateStatus'])
                ->name('crop-categories.status');
        });

        Route::middleware('can:manage-knowledge-sources')->group(function (): void {
            Route::apiResource('knowledge-sources', KnowledgeSourceController::class)
                ->parameters(['knowledge-sources' => 'knowledgeSource']);
            Route::patch('knowledge-sources/{knowledgeSource}/status', [KnowledgeSourceController::class, 'updateStatus'])
                ->name('knowledge-sources.status');
            Route::post('knowledge-sources/{knowledgeSource}/review', [KnowledgeSourceReviewController::class, 'store'])
                ->middleware('can:review-knowledge-sources')->name('knowledge-sources.review');
            Route::post('knowledge-sources/{knowledgeSource}/attachments', [KnowledgeSourceAttachmentController::class, 'store'])
                ->name('knowledge-sources.attachments.store');
            Route::get('knowledge-sources/{knowledgeSource}/attachments/{attachment}', [KnowledgeSourceAttachmentController::class, 'download'])
                ->scopeBindings()->name('knowledge-sources.attachments.download');
            Route::delete('knowledge-sources/{knowledgeSource}/attachments/{attachment}', [KnowledgeSourceAttachmentController::class, 'destroy'])
                ->scopeBindings()->name('knowledge-sources.attachments.destroy');
        });
    });
});

Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login');

Route::apiResource('regions', RegionController::class)->only(['index', 'show']);

Route::middleware(['auth:frontend', 'abilities:frontend'])->group(function (): void {
    Route::get('me', [AuthController::class, 'me'])->name('me');
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');
});
