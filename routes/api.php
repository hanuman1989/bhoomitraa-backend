<?php

use App\Http\Controllers\Account\AuthController as AccountAuthController;
use App\Http\Controllers\Admin\ActivityCategoryController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\CropActivityTemplateController;
use App\Http\Controllers\Admin\CropCategoryController;
use App\Http\Controllers\Admin\CropController;
use App\Http\Controllers\Admin\CropSeedRateController;
use App\Http\Controllers\Admin\CropStageController;
use App\Http\Controllers\Admin\CropStageTimelineController;
use App\Http\Controllers\Admin\CropVarietyController;
use App\Http\Controllers\Admin\CropYieldBenchmarkController;
use App\Http\Controllers\Admin\FarmerSmartTimelinePreviewController;
use App\Http\Controllers\Admin\FertilizerController;
use App\Http\Controllers\Admin\FertilizerRecommendationController;
use App\Http\Controllers\Admin\KnowledgeSourceAttachmentController;
use App\Http\Controllers\Admin\KnowledgeSourceController;
use App\Http\Controllers\Admin\KnowledgeSourceReviewController;
use App\Http\Controllers\Admin\RegionController;
use App\Http\Controllers\Admin\SeasonController;
use App\Http\Controllers\Admin\SeedController;
use App\Http\Controllers\Admin\SeedLotController;
use App\Http\Controllers\Admin\SeedPriceController;
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

        Route::middleware('can:manage-seeds')->group(function (): void {
            Route::apiResource('crop-seed-rates', CropSeedRateController::class)->parameters(['crop-seed-rates' => 'cropSeedRate']);
            Route::patch('crop-seed-rates/{cropSeedRate}/status', [CropSeedRateController::class, 'updateStatus'])->name('crop-seed-rates.status');
            Route::apiResource('seed-prices', SeedPriceController::class)->parameters(['seed-prices' => 'seedPrice']);
            Route::patch('seed-prices/{seedPrice}/status', [SeedPriceController::class, 'updateStatus'])->name('seed-prices.status');
            Route::apiResource('seed-lots', SeedLotController::class)->parameters(['seed-lots' => 'seedLot']);
            Route::patch('seed-lots/{seedLot}/status', [SeedLotController::class, 'updateStatus'])->name('seed-lots.status');
            Route::patch('seed-lots/{seedLot}/verification', [SeedLotController::class, 'updateVerification'])->name('seed-lots.verification');
            Route::get('seed-lots/{seedLot}/report', [SeedLotController::class, 'report'])->name('seed-lots.report');
        });

        Route::middleware('can:manage-fertilizers')->name('fertilizers.')->prefix('fertilizers')->group(function (): void {
            Route::get('/', [FertilizerController::class, 'index'])->name('index');
            Route::post('step-1', [FertilizerController::class, 'storeStepOne'])->name('step-one.store');
            Route::get('{fertilizer}', [FertilizerController::class, 'show'])->name('show');
            Route::match(['PUT', 'PATCH'], '{fertilizer}/step-1', [FertilizerController::class, 'updateStepOne'])->name('step-one.update');
            Route::match(['PUT', 'PATCH'], '{fertilizer}/step-2', [FertilizerController::class, 'saveStepTwo'])->name('step-two');
            Route::match(['PUT', 'PATCH'], '{fertilizer}/step-3', [FertilizerController::class, 'saveStepThree'])->name('step-three');
            Route::match(['POST', 'PUT', 'PATCH'], '{fertilizer}/step-4', [FertilizerController::class, 'saveStepFour'])->name('step-four');
            Route::get('{fertilizer}/review', [FertilizerController::class, 'review'])->name('review');
            Route::post('{fertilizer}/finalize', [FertilizerController::class, 'finalize'])->name('finalize');
            Route::patch('{fertilizer}/status', [FertilizerController::class, 'updateStatus'])->name('status');
            Route::delete('{fertilizer}', [FertilizerController::class, 'destroy'])->name('destroy');
        });

        Route::middleware('can:manage-seeds')->name('seeds.')->prefix('seeds')->group(function (): void {
            Route::get('/', [SeedController::class, 'index'])->name('index');
            Route::post('step-1', [SeedController::class, 'storeStepOne'])->name('step-one.store');
            Route::get('{seed}', [SeedController::class, 'show'])->name('show');
            Route::match(['PUT', 'PATCH'], '{seed}/step-1', [SeedController::class, 'updateStepOne'])->name('step-one.update');
            Route::match(['PUT', 'PATCH'], '{seed}/step-2', [SeedController::class, 'saveStepTwo'])->name('step-two');
            Route::match(['POST', 'PUT', 'PATCH'], '{seed}/step-3', [SeedController::class, 'saveStepThree'])->name('step-three');
            Route::get('{seed}/review', [SeedController::class, 'review'])->name('review');
            Route::post('{seed}/finalize', [SeedController::class, 'finalize'])->name('finalize');
            Route::patch('{seed}/status', [SeedController::class, 'updateStatus'])->name('status');
            Route::delete('{seed}', [SeedController::class, 'destroy'])->name('destroy');
        });

        Route::middleware('can:manage-activity-categories')->group(function (): void {
            Route::apiResource('activity-categories', ActivityCategoryController::class)
                ->parameters(['activity-categories' => 'activityCategory']);
            Route::patch('activity-categories/{activityCategory}/status', [ActivityCategoryController::class, 'updateStatus'])
                ->name('activity-categories.status');
        });

        Route::middleware('can:manage-seasons')->group(function (): void {
            Route::apiResource('seasons', SeasonController::class);
            Route::patch('seasons/{season}/status', [SeasonController::class, 'updateStatus'])->name('seasons.status');
        });

        Route::middleware('can:manage-crops')->group(function (): void {
            Route::get('fertilizer-recommendations/options', [FertilizerRecommendationController::class, 'options'])->name('fertilizer-recommendations.options');
            Route::apiResource('fertilizer-recommendations', FertilizerRecommendationController::class)->parameters(['fertilizer-recommendations' => 'fertilizerRecommendation']);
            Route::patch('fertilizer-recommendations/{fertilizerRecommendation}/status', [FertilizerRecommendationController::class, 'updateStatus'])->name('fertilizer-recommendations.status');
            Route::apiResource('crop-yield-benchmarks', CropYieldBenchmarkController::class)->parameters(['crop-yield-benchmarks' => 'cropYieldBenchmark']);
            Route::patch('crop-yield-benchmarks/{cropYieldBenchmark}/status', [CropYieldBenchmarkController::class, 'updateStatus'])->name('crop-yield-benchmarks.status');
            Route::post('farmer-smart-timeline/preview', FarmerSmartTimelinePreviewController::class)
                ->name('farmer-smart-timeline.preview');
            Route::apiResource('crop-activity-templates', CropActivityTemplateController::class)
                ->parameters(['crop-activity-templates' => 'cropActivityTemplate']);
            Route::patch('crop-activity-templates/{cropActivityTemplate}/status', [CropActivityTemplateController::class, 'updateStatus'])
                ->name('crop-activity-templates.status');
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

Route::apiResource('regions', RegionController::class)->only(['index', 'show']);

Route::prefix('account')->name('account.')->group(function (): void {
    Route::post('login', [AccountAuthController::class, 'login'])->middleware('throttle:login')->name('login');

    Route::middleware(['auth:user', 'abilities:frontend', 'user.active'])->group(function (): void {
        Route::get('me', [AccountAuthController::class, 'me'])->name('me');
        Route::post('logout', [AccountAuthController::class, 'logout'])->name('logout');
    });
});
