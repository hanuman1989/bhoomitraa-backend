<?php

use App\Http\Controllers\Account\AuthController as AccountAuthController;
use App\Http\Controllers\Account\FarmController as AccountFarmController;
use App\Http\Controllers\Account\FarmCropController;
use App\Http\Controllers\Account\FertilizerPriceController;
use App\Http\Controllers\Account\UserController as AccountUserController;
use App\Http\Controllers\Admin\ActivityCategoryController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\CropActivityTemplateController;
use App\Http\Controllers\Admin\CropActivityTemplateTimelineBuilderController;
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
            Route::prefix('crop-activity-templates')->name('crop-activity-templates.')->group(function (): void {
                Route::get('timeline-builder/metadata', [CropActivityTemplateTimelineBuilderController::class, 'metadata'])
                    ->name('timeline-builder.metadata');
                Route::post('timeline-builder/resolve', [CropActivityTemplateTimelineBuilderController::class, 'resolve'])
                    ->name('timeline-builder.resolve');
                Route::post('bulk', [CropActivityTemplateTimelineBuilderController::class, 'bulk'])->name('bulk');
                Route::post('import', [CropActivityTemplateTimelineBuilderController::class, 'import'])->name('import');
            });
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
    Route::post('register', [AccountUserController::class, 'register'])->middleware('throttle:account-register')->name('register');
    Route::post('forgot-password', [AccountUserController::class, 'forgotPassword'])->middleware('throttle:account-password')->name('forgot-password');
    Route::post('reset-password', [AccountUserController::class, 'resetPassword'])->middleware('throttle:account-password')->name('reset-password');
    Route::post('login', [AccountAuthController::class, 'login'])->middleware('throttle:login')->name('login');

    Route::middleware(['auth:user', 'abilities:frontend', 'user.active'])->group(function (): void {
        Route::get('regions', [App\Http\Controllers\Account\RegionController::class, 'getList'])->name('regions.list');
        Route::get('seasons', [App\Http\Controllers\Account\SeasonController::class, 'getList'])->name('seasons.list');
        Route::get('crop-categories', [App\Http\Controllers\Account\CropCategoryController::class, 'getList'])->name('crop-categories.list');
        Route::get('crops/list', [App\Http\Controllers\Account\CropController::class, 'getList'])->name('crops.list');
        Route::get('crop-varieties', [App\Http\Controllers\Account\CropVarietyController::class, 'getList'])->name('crop-varieties.list');
        Route::get('seeds', [App\Http\Controllers\Account\SeedController::class, 'getList'])->name('seeds.list');
        Route::get('seed-lots', [App\Http\Controllers\Account\SeedLotController::class, 'getList'])->name('seed-lots.list');
        Route::get('seed-prices', [App\Http\Controllers\Account\SeedPriceController::class, 'getList'])->name('seed-prices.list');
        Route::get('crop-seed-rates', [App\Http\Controllers\Account\CropSeedRateController::class, 'getList'])->name('crop-seed-rates.list');
        Route::get('fertilizers', [App\Http\Controllers\Account\FertilizerController::class, 'getList'])->name('fertilizers.list');
        Route::get('fertilizer-recommendations', [App\Http\Controllers\Account\FertilizerRecommendationController::class, 'getList'])->name('fertilizer-recommendations.list');
        Route::get('fertilizer-prices', [FertilizerPriceController::class, 'getList'])->name('fertilizer-prices.list');
        Route::get('crop-yield-benchmarks', [App\Http\Controllers\Account\CropYieldBenchmarkController::class, 'getList'])->name('crop-yield-benchmarks.list');

        Route::get('farms/{farmId}/crop-boundaries', [FarmCropController::class, 'getFarmCropBoundaries'])->whereNumber('farmId')->name('farms.crop-boundaries');
        Route::prefix('farm-crops')->name('farm-crops.')->controller(FarmCropController::class)->group(function (): void {
            Route::get('/', 'getList')->name('list');
            Route::get('summary', 'summary')->name('summary');
            Route::get('map', 'getMapList')->name('map');
            Route::post('/', 'saveStepOne')->name('store');
            Route::get('{id}', 'show')->whereNumber('id')->name('show');
            Route::put('{id}/step-1', 'updateStepOne')->whereNumber('id')->name('step-1');
            Route::put('{id}/step-2', 'saveStepTwo')->whereNumber('id')->name('step-2');
            Route::put('{id}/step-3', 'saveStepThree')->whereNumber('id')->name('step-3');
            Route::match(['POST', 'PUT'], '{id}/step-4', 'saveStepFour')->whereNumber('id')->name('step-4');
            Route::put('{id}/step-5', 'saveStepFive')->whereNumber('id')->name('step-5');
            Route::get('{id}/seed-recommendation', 'getSeedRecommendation')->whereNumber('id')->name('seed-recommendation');
            Route::get('{id}/fertilizer-recommendation', 'getFertilizerRecommendation')->whereNumber('id')->name('fertilizer-recommendation');
            Route::get('{id}/review', 'review')->whereNumber('id')->name('review');
            Route::post('{id}/finalize', 'finalize')->whereNumber('id')->name('finalize');
            Route::delete('{id}', 'destroy')->whereNumber('id')->name('destroy');
            Route::delete('{id}/edit-draft', 'discardEdit')->whereNumber('id')->name('discard-edit');
            Route::get('{id}/soil-report', 'currentSoilReport')->whereNumber('id')->name('current-soil-report');
            Route::get('{id}/soil-tests/{soilTest}/report', 'soilReport')->whereNumber(['id', 'soilTest'])->name('soil-report');
        });
        Route::get('area-units', [AccountUserController::class, 'areaUnits'])->name('area-units');
        Route::get('farms/summary', [AccountFarmController::class, 'summary'])->name('farms.summary');
        Route::get('farms', [AccountFarmController::class, 'index'])->name('farms.index');
        Route::post('farms', [AccountFarmController::class, 'store'])->name('farms.store');
        Route::get('farms/{farm}', [AccountFarmController::class, 'show'])->whereNumber('farm')->name('farms.show');
        Route::patch('farms/{farm}', [AccountFarmController::class, 'update'])->whereNumber('farm')->name('farms.update');
        Route::patch('farms/{farm}/details', [AccountFarmController::class, 'updateAdditionalDetails'])->whereNumber('farm')->name('farms.details');
        Route::patch('farms/{farm}/boundary', [AccountFarmController::class, 'updateBoundary'])->whereNumber('farm')->name('farms.boundary');
        Route::patch('farms/{farm}/status', [AccountFarmController::class, 'updateStatus'])->whereNumber('farm')->name('farms.status');
        Route::patch('profile/personal', [AccountUserController::class, 'updatePersonalDetails'])->name('profile.personal');
        Route::patch('profile/location', [AccountUserController::class, 'updateLocationDetails'])->name('profile.location');
        Route::get('me', [AccountAuthController::class, 'me'])->name('me');
        Route::post('logout', [AccountAuthController::class, 'logout'])->name('logout');
    });
});
