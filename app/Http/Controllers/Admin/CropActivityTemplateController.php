<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCropActivityTemplateRequest;
use App\Http\Requests\Admin\UpdateCropActivityTemplateRequest;
use App\Http\Requests\Admin\UpdateCropActivityTemplateStatusRequest;
use App\Http\Resources\Admin\CropActivityTemplateResource;
use App\Models\CropActivityTemplate;
use App\Models\CropActivityTemplateTranslation;
use App\Services\CropActivityTemplateAssessor;
use App\Services\CropActivityTemplateWriter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use PDOException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class CropActivityTemplateController extends Controller
{
    public function __construct(
        private CropActivityTemplateWriter $writer,
        private CropActivityTemplateAssessor $assessor,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'keyword' => ['sometimes', 'nullable', 'string', 'max:150'],
            'crop_id' => ['sometimes', 'integer', 'min:1', Rule::exists('crops', 'id')],
            'crop_variety_id' => ['sometimes', 'integer', 'min:1', Rule::exists('crop_varieties', 'id')],
            'category_id' => ['sometimes', 'integer', 'min:1', Rule::exists('activity_categories', 'id')],
            'crop_stage_id' => ['sometimes', 'integer', 'min:1', Rule::exists('crop_stages', 'id')],
            'season_id' => ['sometimes', 'integer', 'min:1', Rule::exists('seasons', 'id')],
            'source_id' => ['sometimes', 'integer', 'min:1', Rule::exists('knowledge_sources', 'id')],
            'region_id' => ['sometimes', 'integer', 'min:1', Rule::exists('regions', 'id')],
            'variety_scope' => ['sometimes', Rule::in(CropActivityTemplate::VARIETY_SCOPES)],
            'season_scope' => ['sometimes', Rule::in(CropActivityTemplate::SEASON_SCOPES)],
            'region_scope' => ['sometimes', Rule::in(CropActivityTemplate::REGION_SCOPES)],
            'schedule_type' => ['sometimes', Rule::in(CropActivityTemplate::SCHEDULE_TYPES)],
            'schedule_basis' => ['sometimes', Rule::in(CropActivityTemplate::SCHEDULE_BASES)],
            'activity_type' => ['sometimes', Rule::in(CropActivityTemplate::ACTIVITY_TYPES)],
            'priority' => ['sometimes', Rule::in(CropActivityTemplate::PRIORITIES)],
            'status' => ['sometimes', 'boolean'],
            'include_assessment' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        try {
            $query = CropActivityTemplate::query()->withLocalizedTitle()->with($this->writer->displayRelations());
            foreach (['crop_id', 'category_id', 'crop_stage_id', 'schedule_type', 'schedule_basis', 'activity_type', 'priority', 'variety_scope', 'season_scope', 'region_scope'] as $field) {
                if (array_key_exists($field, $filters)) {
                    $query->where('crop_activity_templates.'.$field, $filters[$field]);
                }
            }
            if (array_key_exists('crop_variety_id', $filters)) {
                $varietyId = $filters['crop_variety_id'];
                $query->where(fn (Builder $query): Builder => $query
                    ->where('variety_scope', 'all')
                    ->orWhereHas('varieties', fn (Builder $varieties): Builder => $varieties->whereKey($varietyId)));
            }
            if (array_key_exists('season_id', $filters)) {
                $seasonId = $filters['season_id'];
                $query->where(fn (Builder $query): Builder => $query
                    ->where('season_scope', 'all')
                    ->orWhereHas('seasons', fn (Builder $seasons): Builder => $seasons->whereKey($seasonId)));
            }
            if (array_key_exists('source_id', $filters)) {
                $query->whereHas('knowledgeSources', fn (Builder $sources): Builder => $sources->whereKey($filters['source_id']));
            }
            if (array_key_exists('region_id', $filters)) {
                $regionId = $filters['region_id'];
                $query->where(fn (Builder $query): Builder => $query
                    ->where('region_scope', 'all')
                    ->orWhereHas('regions', fn (Builder $regions): Builder => $regions->whereKey($regionId)));
            }
            if (array_key_exists('status', $filters)) {
                $query->where('crop_activity_templates.is_active', $filters['status']);
            }
            $keyword = trim($filters['keyword'] ?? '');
            if ($keyword !== '') {
                $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $keyword).'%';
                $matchingNames = static fn (Builder $translations): Builder => $translations
                    ->whereIn('locale', array_keys(config('languages.supported')))->whereRaw("name LIKE ? ESCAPE '!'", [$pattern]);
                $query->where(function (Builder $query) use ($matchingNames, $pattern): void {
                    $query->whereHas('translations', fn (Builder $translations): Builder => $translations
                        ->whereIn('locale', array_keys(config('languages.supported')))->whereRaw("title LIKE ? ESCAPE '!'", [$pattern]))
                        ->orWhereHas('crop.translations', $matchingNames)->orWhereHas('category.translations', $matchingNames);
                });
            }
            $templates = $query->orderByDesc('crop_activity_templates.created_at')->orderByDesc('crop_activity_templates.id')
                ->paginate($request->integer('per_page', 15))
                ->appends($request->only([
                    'keyword', 'crop_id', 'crop_variety_id', 'category_id', 'crop_stage_id', 'season_id',
                    'source_id', 'region_id', 'variety_scope', 'season_scope', 'region_scope',
                    'schedule_type', 'schedule_basis', 'activity_type', 'priority', 'status', 'include_assessment', 'per_page',
                ]));
            $this->attachListingDetails($templates->getCollection());
            if ($request->boolean('include_assessment')) {
                foreach ($templates->getCollection() as $template) {
                    $this->withAssessment($template, $template->listed_region_ids);
                }
            }
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to retrieve crop activity templates. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => CropActivityTemplateResource::collection($templates),
            'pagination' => [
                'current_page' => $templates->currentPage(), 'last_page' => $templates->lastPage(),
                'per_page' => $templates->perPage(), 'total' => $templates->total(),
                'from' => $templates->firstItem(), 'to' => $templates->lastItem(),
                'next_page_url' => $templates->nextPageUrl(), 'prev_page_url' => $templates->previousPageUrl(),
            ],
            'message' => 'Crop activity templates retrieved successfully.',
        ]);
    }

    public function store(StoreCropActivityTemplateRequest $request): JsonResponse
    {
        try {
            $result = $this->writer->create($request->validated());
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to create the crop activity template. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => new CropActivityTemplateResource($this->withAssessment($result['template'])),
            'pagination' => null,
            'message' => $result['created']
                ? 'Crop activity template created successfully.'
                : 'Crop activity template was already created for this request.',
        ], $result['created'] ? 201 : 200);
    }

    public function show(CropActivityTemplate $cropActivityTemplate): JsonResponse
    {
        $cropActivityTemplate->load($this->writer->displayRelations(includeEditData: true));

        return response()->json([
            'status' => true,
            'data' => new CropActivityTemplateResource($this->withAssessment($cropActivityTemplate)),
            'pagination' => null,
            'message' => 'Crop activity template retrieved successfully.',
        ]);
    }

    public function update(UpdateCropActivityTemplateRequest $request, CropActivityTemplate $cropActivityTemplate): JsonResponse
    {
        try {
            $template = $this->writer->save($cropActivityTemplate, $request->validated());
        } catch (ConflictHttpException $exception) {
            return $this->conflict($exception->getMessage());
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to update the crop activity template. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => new CropActivityTemplateResource($this->withAssessment($template)),
            'pagination' => null,
            'message' => 'Crop activity template updated successfully.',
        ]);
    }

    public function updateStatus(UpdateCropActivityTemplateStatusRequest $request, CropActivityTemplate $cropActivityTemplate): JsonResponse
    {
        try {
            $template = $this->writer->changeStatus(
                $cropActivityTemplate,
                $request->boolean('is_active'),
                $request->has('expected_version') ? $request->integer('expected_version') : null,
            );
        } catch (ConflictHttpException $exception) {
            return $this->conflict($exception->getMessage());
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to update the crop activity template status. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => new CropActivityTemplateResource($this->withAssessment($template)),
            'pagination' => null,
            'message' => 'Crop activity template status updated successfully.',
        ]);
    }

    public function destroy(CropActivityTemplate $cropActivityTemplate): JsonResponse
    {
        try {
            if (! $this->writer->delete($cropActivityTemplate)) {
                return $this->deletionConflict();
            }
        } catch (QueryException $exception) {
            if (in_array($exception->errorInfo[1] ?? null, [1451, 787], true)
                || (($exception->errorInfo[0] ?? null) === '23000' && str_contains($exception->getMessage(), 'FOREIGN KEY constraint failed'))) {
                return $this->deletionConflict();
            }

            return $this->databaseFailure($exception, 'Unable to delete the crop activity template. Please try again later.');
        } catch (PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to delete the crop activity template. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => null,
            'pagination' => null,
            'message' => 'Crop activity template deleted successfully.',
        ]);
    }

    /** @param list<int>|null $regionIds */
    private function withAssessment(CropActivityTemplate $template, ?array $regionIds = null): CropActivityTemplate
    {
        return $template->setAttribute('timeline_assessment', $this->assessor->assess($template, $regionIds));
    }

    /**
     * Attach every translated title and district selection to listed templates in two queries.
     *
     * @param  Collection<int, CropActivityTemplate>  $templates
     */
    private function attachListingDetails(Collection $templates): void
    {
        $ids = $templates->modelKeys();
        if ($ids === []) {
            return;
        }
        $titles = CropActivityTemplateTranslation::query()->whereIn('crop_activity_template_id', $ids)
            ->whereIn('locale', array_keys(config('languages.supported')))->orderBy('locale')
            ->get(['crop_activity_template_id', 'locale', 'title'])->groupBy('crop_activity_template_id');
        $regions = DB::table('crop_activity_template_regions')->whereIn('crop_activity_template_id', $ids)
            ->orderBy('region_id')->get(['crop_activity_template_id', 'region_id'])->groupBy('crop_activity_template_id');
        foreach ($templates as $template) {
            $template->setAttribute('translation_titles', $titles->get($template->id, collect())
                ->mapWithKeys(fn (CropActivityTemplateTranslation $translation): array => [$translation->locale => $translation->title])->all());
            $template->setAttribute('listed_region_ids', $template->region_scope === 'all' ? [] : $regions->get($template->id, collect())
                ->map(fn (object $row): int => (int) $row->region_id)->values()->all());
        }
    }

    private function conflict(string $message): JsonResponse
    {
        return response()->json([
            'status' => false,
            'data' => null,
            'pagination' => null,
            'message' => $message,
        ], 409);
    }

    private function deletionConflict(): JsonResponse
    {
        return response()->json([
            'status' => false,
            'data' => null,
            'pagination' => null,
            'message' => 'The crop activity template cannot be deleted while active or referenced by other records. Deactivate it instead.',
        ], 409);
    }
}
