<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveSowingMethodImagesRequest;
use App\Http\Requests\Admin\SaveSowingMethodTranslationsRequest;
use App\Http\Requests\Admin\StoreSowingMethodRequest;
use App\Http\Requests\Admin\UpdateSowingMethodRequest;
use App\Http\Requests\Admin\UpdateSowingMethodStatusRequest;
use App\Http\Resources\Admin\SowingMethodResource;
use App\Models\SowingMethod;
use App\Services\SowingMethodManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SowingMethodController extends Controller
{
    public function __construct(private SowingMethodManager $manager) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'keyword' => ['sometimes', 'nullable', 'string', 'max:150'],
            'is_active' => ['sometimes', 'boolean'],
            'establishment_type' => ['sometimes', Rule::in(SowingMethod::ESTABLISHMENT_TYPES)],
            'crop_scope' => ['sometimes', Rule::in(SowingMethod::CROP_SCOPES)],
            'crop_id' => ['sometimes', 'integer', 'min:1', Rule::exists('crops', 'id')],
            'machinery_requirement' => ['sometimes', Rule::in(SowingMethod::MACHINERY_REQUIREMENTS)],
            'labour_requirement' => ['sometimes', Rule::in(SowingMethod::LABOUR_REQUIREMENTS)],
            'cost_level' => ['sometimes', Rule::in(SowingMethod::COST_LEVELS)],
            'sort_by' => ['sometimes', Rule::in(['id', 'code', 'slug', 'title', 'sort_order', 'is_active', 'created_at', 'updated_at'])],
            'sort_direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $query = SowingMethod::query()->withLocalizedTitle()->with($this->relations(detail: false));
        foreach (['is_active', 'establishment_type', 'crop_scope', 'machinery_requirement', 'labour_requirement', 'cost_level'] as $field) {
            if (array_key_exists($field, $filters)) {
                $query->where('sowing_methods.'.$field, $filters[$field]);
            }
        }
        if (isset($filters['crop_id'])) {
            $query->applicableToCrop((int) $filters['crop_id']);
        }
        $keyword = trim($filters['keyword'] ?? '');
        if ($keyword !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $keyword).'%';
            $query->where(function (Builder $query) use ($pattern): void {
                $query->whereRaw("sowing_methods.code LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("sowing_methods.slug LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereHas('translations', fn (Builder $translation): Builder => $translation
                        ->whereIn('locale', SowingMethod::requiredLocales())->whereRaw("title LIKE ? ESCAPE '!'", [$pattern]));
            });
        }
        $sort = $filters['sort_by'] ?? 'sort_order';
        $query->orderBy($sort === 'title' ? 'localized_title' : 'sowing_methods.'.$sort, $filters['sort_direction'] ?? 'asc');
        if ($sort !== 'id') {
            $query->orderBy('sowing_methods.id');
        }
        $methods = $query->paginate($request->integer('per_page', 15))->appends(Arr::except($filters, ['page']));

        return response()->json([
            'status' => true, 'data' => SowingMethodResource::collection($methods),
            'pagination' => ['current_page' => $methods->currentPage(), 'last_page' => $methods->lastPage(),
                'per_page' => $methods->perPage(), 'total' => $methods->total(), 'from' => $methods->firstItem(),
                'to' => $methods->lastItem(), 'next_page_url' => $methods->nextPageUrl(), 'prev_page_url' => $methods->previousPageUrl()],
            'message' => 'Sowing methods retrieved successfully.',
        ]);
    }

    public function options(): JsonResponse
    {
        $options = [];
        foreach (['establishment_types' => SowingMethod::ESTABLISHMENT_TYPES, 'crop_scopes' => SowingMethod::CROP_SCOPES,
            'machinery_requirements' => SowingMethod::MACHINERY_REQUIREMENTS, 'labour_requirements' => SowingMethod::LABOUR_REQUIREMENTS,
            'cost_levels' => SowingMethod::COST_LEVELS] as $key => $values) {
            $options[$key] = array_map(fn (string $value): array => ['value' => $value, 'label' => Str::headline($value)], $values);
        }
        $options['supported_locales'] = collect(config('languages.supported'))->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values()->all();

        return response()->json(['status' => true, 'data' => $options, 'pagination' => null, 'message' => 'Sowing method options retrieved successfully.']);
    }

    public function store(StoreSowingMethodRequest $request): JsonResponse
    {
        $method = $this->manager->saveBasicDetails($request->validated());

        return response()->json(['status' => true, 'data' => new SowingMethodResource($this->detail($method)), 'pagination' => null, 'message' => 'Sowing method basic details saved successfully.'], 201);
    }

    public function show(SowingMethod $sowingMethod): JsonResponse
    {
        return response()->json(['status' => true, 'data' => new SowingMethodResource($this->detail($sowingMethod)), 'pagination' => null, 'message' => 'Sowing method retrieved successfully.']);
    }

    public function update(UpdateSowingMethodRequest $request, SowingMethod $sowingMethod): JsonResponse
    {
        $method = $this->manager->saveBasicDetails($request->validated(), $sowingMethod);

        return response()->json(['status' => true, 'data' => new SowingMethodResource($this->detail($method)), 'pagination' => null, 'message' => 'Sowing method basic details updated successfully.']);
    }

    public function saveStepTwo(SaveSowingMethodTranslationsRequest $request, SowingMethod $sowingMethod): JsonResponse
    {
        $method = $this->manager->saveTranslations($sowingMethod, $request->validated('translations'));

        return response()->json(['status' => true, 'data' => new SowingMethodResource($this->detail($method)), 'pagination' => null, 'message' => 'Sowing method translations saved successfully.']);
    }

    public function saveStepThree(SaveSowingMethodImagesRequest $request, SowingMethod $sowingMethod): JsonResponse
    {
        $method = $this->manager->saveImages($sowingMethod, $request->validated(), $request->file('images', []));

        return response()->json(['status' => true, 'data' => new SowingMethodResource($this->detail($method)), 'pagination' => null, 'message' => 'Sowing method images saved successfully.']);
    }

    public function finalize(UpdateSowingMethodStatusRequest $request, SowingMethod $sowingMethod): JsonResponse
    {
        $method = $this->manager->publish($sowingMethod, $request->boolean('is_active'), finalize: true);

        return response()->json(['status' => true, 'data' => new SowingMethodResource($this->detail($method)), 'pagination' => null, 'message' => 'Sowing method saved successfully.']);
    }

    public function updateStatus(UpdateSowingMethodStatusRequest $request, SowingMethod $sowingMethod): JsonResponse
    {
        $method = $this->manager->publish($sowingMethod, $request->boolean('is_active'));

        return response()->json(['status' => true, 'data' => new SowingMethodResource($this->detail($method)), 'pagination' => null, 'message' => 'Sowing method status updated successfully.']);
    }

    public function destroy(SowingMethod $sowingMethod): JsonResponse
    {
        if (! $this->manager->delete($sowingMethod)) {
            return response()->json(['status' => false, 'data' => null, 'pagination' => null,
                'message' => 'Referenced sowing methods cannot be deleted. Deactivate the method instead.'], 409);
        }

        return response()->json(['status' => true, 'data' => null, 'pagination' => null, 'message' => 'Sowing method deleted successfully.']);
    }

    private function detail(SowingMethod $method): SowingMethod
    {
        $method->load($this->relations());
        $method->setAttribute('readiness_errors', $this->manager->completionErrors($method));

        return $method;
    }

    /** @return array<int|string, mixed> */
    private function relations(bool $detail = true): array
    {
        $relations = ['coverImage', 'crops:id', 'crops.translations' => fn (HasMany $translations): HasMany => $translations
            ->whereIn('locale', array_unique([app()->getLocale(), config('languages.default')]))->select(['id', 'crop_id', 'locale', 'name'])];
        if ($detail) {
            $relations[] = 'translations';
            $relations[] = 'images';
        } else {
            $relations['translations'] = fn (HasMany $translations): HasMany => $translations
                ->whereIn('locale', array_unique([app()->getLocale(), config('languages.default')]))->select(['id', 'sowing_method_id', 'locale', 'title']);
        }

        return $relations;
    }
}
