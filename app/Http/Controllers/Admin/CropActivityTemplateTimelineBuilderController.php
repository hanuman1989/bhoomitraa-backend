<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BulkCropActivityTemplateRequest;
use App\Http\Requests\Admin\ImportCropActivityTemplatesRequest;
use App\Http\Requests\Admin\ResolveStageTimelinesRequest;
use App\Models\CropActivityTemplate;
use App\Services\CropActivityTemplateBulkProcessor;
use App\Services\CropActivityTemplateImporter;
use App\Services\StageTimelineResolver;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use PDOException;

class CropActivityTemplateTimelineBuilderController extends Controller
{
    public function resolve(ResolveStageTimelinesRequest $request, StageTimelineResolver $resolver): JsonResponse
    {
        $data = $request->validated();
        try {
            $stages = $resolver->resolve(
                (int) $data['crop_id'],
                array_map('intval', $data['crop_stage_ids']),
                $data['variety_scope'], array_map('intval', $data['crop_variety_ids'] ?? []),
                $data['season_scope'], array_map('intval', $data['season_ids'] ?? []),
                $data['region_scope'], array_map('intval', $data['region_ids'] ?? []),
            );
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to resolve stage timelines. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => ['crop_id' => (int) $data['crop_id'], 'stages' => $stages],
            'pagination' => null,
            'message' => 'Stage timelines resolved successfully.',
        ]);
    }

    public function bulk(BulkCropActivityTemplateRequest $request, CropActivityTemplateBulkProcessor $processor): JsonResponse
    {
        $data = $request->validated();
        $outcome = $processor->process(array_map('intval', $data['ids']), $data['operation'], $data['data'] ?? []);
        $totals = $outcome['totals'];

        return response()->json([
            'status' => true,
            'data' => ['operation' => $data['operation'], ...$outcome],
            'pagination' => null,
            'message' => 'Bulk operation completed: '.$totals['updated'].' updated, '.$totals['skipped'].' skipped, '.$totals['failed'].' failed.',
        ]);
    }

    public function import(ImportCropActivityTemplatesRequest $request, CropActivityTemplateImporter $importer): JsonResponse
    {
        $data = $request->validated();
        $rows = array_values($data['templates']);
        if ($data['mode'] === 'preview') {
            $results = $importer->preview($rows);
            $valid = count(array_filter($results, fn (array $result): bool => $result['status'] === 'valid'));

            return response()->json([
                'status' => true,
                'data' => [
                    'mode' => 'preview',
                    'results' => $results,
                    'totals' => ['requested' => count($rows), 'valid' => $valid, 'invalid' => count($rows) - $valid],
                ],
                'pagination' => null,
                'message' => 'Import preview completed: '.$valid.' of '.count($rows).' templates are valid.',
            ]);
        }
        try {
            $results = $importer->commit($rows);
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to import crop activity templates. Please try again later.');
        }
        $created = count(array_filter($results, fn (array $result): bool => $result['status'] === 'created'));

        return response()->json([
            'status' => true,
            'data' => [
                'mode' => 'commit',
                'results' => $results,
                'totals' => ['requested' => count($rows), 'created' => $created, 'existing' => count($rows) - $created],
            ],
            'pagination' => null,
            'message' => 'Imported '.$created.' crop activity templates as inactive drafts.',
        ], $created > 0 ? 201 : 200);
    }

    public function metadata(): JsonResponse
    {
        $supported = config('languages.supported', []);

        return response()->json([
            'status' => true,
            'data' => [
                'locales' => [
                    'default' => config('languages.default'),
                    'supported' => array_map(
                        fn (string $code, string $name): array => ['code' => $code, 'name' => $name],
                        array_keys($supported), array_values($supported),
                    ),
                    'required' => CropActivityTemplate::REQUIRED_LOCALES,
                ],
                'schedule_types' => CropActivityTemplate::SCHEDULE_TYPES,
                'schedule_bases' => CropActivityTemplate::SCHEDULE_BASES,
                'activity_types' => CropActivityTemplate::ACTIVITY_TYPES,
                'priorities' => CropActivityTemplate::PRIORITIES,
                'variety_scopes' => CropActivityTemplate::VARIETY_SCOPES,
                'season_scopes' => CropActivityTemplate::SEASON_SCOPES,
                'region_scopes' => CropActivityTemplate::REGION_SCOPES,
                'smart_actions' => array_values(config('crop_activity_templates.smart_actions', [])),
                'bulk_operations' => array_keys(BulkCropActivityTemplateRequest::OPERATION_FIELDS),
                'limits' => [
                    'max_offset_days' => CropActivityTemplate::MAX_OFFSET_DAYS,
                    'max_display_order' => CropActivityTemplate::MAX_DISPLAY_ORDER,
                    'max_timing_exception_reason_length' => CropActivityTemplate::MAX_TIMING_EXCEPTION_REASON_LENGTH,
                    'bulk_max_ids' => BulkCropActivityTemplateRequest::MAX_IDS,
                    'import_max_templates' => ImportCropActivityTemplatesRequest::MAX_TEMPLATES,
                    'resolve_max_stages' => ResolveStageTimelinesRequest::MAX_STAGES,
                ],
            ],
            'pagination' => null,
            'message' => 'Timeline builder metadata retrieved successfully.',
        ]);
    }
}
