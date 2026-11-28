<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FinalizeCropFieldLayoutRequest;
use App\Http\Requests\Admin\SaveCropFieldLayoutConfigurationRequest;
use App\Http\Requests\Admin\SaveCropFieldLayoutTranslationsRequest;
use App\Http\Requests\Admin\StoreCropFieldLayoutRequest;
use App\Http\Requests\Admin\UpdateCropFieldLayoutRequest;
use App\Http\Resources\Admin\CropFieldLayoutResource;
use App\Models\Crop;
use App\Models\CropFieldLayout;
use App\Models\CropVariety;
use App\Models\SowingMethod;
use App\Services\CropFieldLayoutManager;
use App\Services\CropFieldLayoutOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CropFieldLayoutController extends Controller
{
    public function __construct(private CropFieldLayoutManager $manager) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'crop_id' => ['sometimes', 'integer', 'min:1', Rule::exists('crops', 'id')],
            'sowing_method_id' => ['sometimes', 'integer', 'min:1', Rule::exists('sowing_methods', 'id')],
            'layout_type' => ['sometimes', Rule::in(array_keys(CropFieldLayoutOptions::layouts()))],
            'farming_type' => ['sometimes', Rule::in(array_keys(CropFieldLayoutOptions::FARMING_TYPES))],
            'keyword' => ['sometimes', 'nullable', 'string', 'max:150'],
            'is_active' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);
        $query = CropFieldLayout::query()->with($this->relations(false));
        foreach (['sowing_method_id', 'layout_type', 'farming_type', 'is_active'] as $field) {
            if (array_key_exists($field, $filters)) {
                $query->where('crop_field_layouts.'.$field, $filters[$field]);
            }
        }
        if (isset($filters['crop_id'])) {
            $query->where(fn (Builder $query): Builder => $query->where('crop_scope', 'all')
                ->orWhere(fn (Builder $specific): Builder => $specific->where('crop_scope', 'specific')
                    ->whereHas('crops', fn (Builder $crop): Builder => $crop->whereKey($filters['crop_id']))));
        }
        $keyword = trim($filters['keyword'] ?? '');
        if ($keyword !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $keyword).'%';
            $query->where(function (Builder $query) use ($pattern): void {
                $this->whereLocalizedMatch($query, 'title', $pattern);
                $query->orWhereHas('crops', function (Builder $crop) use ($pattern): void {
                    $this->whereLocalizedMatch($crop, 'name', $pattern);
                });
            });
        }
        $layouts = $query->orderBy('sort_order')->orderBy('id')->paginate($request->integer('per_page', 15))
            ->appends(Arr::except($filters, ['page']));
        foreach ($layouts as $layout) {
            $layout->setAttribute('wizard_state', $this->manager->wizard($layout, readiness: false));
        }

        return response()->json([
            'status' => true, 'data' => CropFieldLayoutResource::collection($layouts),
            'pagination' => ['current_page' => $layouts->currentPage(), 'last_page' => $layouts->lastPage(),
                'per_page' => $layouts->perPage(), 'total' => $layouts->total(), 'from' => $layouts->firstItem(),
                'to' => $layouts->lastItem(), 'next_page_url' => $layouts->nextPageUrl(), 'prev_page_url' => $layouts->previousPageUrl()],
            'message' => 'Field layouts retrieved successfully.',
        ]);
    }

    public function options(): JsonResponse
    {
        $options = [];
        foreach (['crop_scopes' => array_combine(CropFieldLayoutOptions::CROP_SCOPES, array_map(Str::headline(...), CropFieldLayoutOptions::CROP_SCOPES)),
            'farming_types' => CropFieldLayoutOptions::FARMING_TYPES, 'growing_systems' => CropFieldLayoutOptions::GROWING_SYSTEMS] as $key => $values) {
            $options[$key] = collect($values)->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values()->all();
        }
        $options['layout_types'] = array_map(CropFieldLayoutOptions::definition(...), array_keys(CropFieldLayoutOptions::layouts()));
        $options['deferred_layout_types'] = CropFieldLayoutOptions::DEFERRED_TYPES;
        $options['supported_locales'] = collect(config('languages.supported'))->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label, 'required' => true])->values()->all();
        $options['default_locale'] = config('languages.default');
        $options['master_data_endpoints'] = ['crops' => route('admin.crops.index'), 'crop_varieties' => route('admin.crop-varieties.index'),
            'sowing_methods' => route('admin.sowing-methods.index')];

        return response()->json(['status' => true, 'data' => $options, 'pagination' => null, 'message' => 'Field layout options retrieved successfully.']);
    }

    public function storeBasicDetails(StoreCropFieldLayoutRequest $request): JsonResponse
    {
        $layout = $this->manager->saveBasicDetails($request->validated());

        return response()->json(['status' => true, 'data' => new CropFieldLayoutResource($this->detail($layout)), 'pagination' => null, 'message' => 'Field layout basic details saved successfully.'], 201);
    }

    public function updateBasicDetails(UpdateCropFieldLayoutRequest $request, CropFieldLayout $cropFieldLayout): JsonResponse
    {
        $layout = $this->manager->saveBasicDetails($request->validated(), $cropFieldLayout);

        return response()->json(['status' => true, 'data' => new CropFieldLayoutResource($this->detail($layout)), 'pagination' => null, 'message' => 'Field layout basic details updated successfully.']);
    }

    public function updateLayoutConfiguration(SaveCropFieldLayoutConfigurationRequest $request, CropFieldLayout $cropFieldLayout): JsonResponse
    {
        $layout = $this->manager->saveConfiguration($cropFieldLayout, $request->validated());

        return response()->json(['status' => true, 'data' => new CropFieldLayoutResource($this->detail($layout)), 'pagination' => null, 'message' => 'Field layout configuration saved successfully.']);
    }

    public function updateTranslations(SaveCropFieldLayoutTranslationsRequest $request, CropFieldLayout $cropFieldLayout): JsonResponse
    {
        $layout = $this->manager->saveTranslations($cropFieldLayout, $request->validated());

        return response()->json(['status' => true, 'data' => new CropFieldLayoutResource($this->detail($layout)), 'pagination' => null, 'message' => 'Field layout translations saved successfully.']);
    }

    public function show(CropFieldLayout $cropFieldLayout): JsonResponse
    {
        return response()->json(['status' => true, 'data' => new CropFieldLayoutResource($this->detail($cropFieldLayout)), 'pagination' => null, 'message' => 'Field layout retrieved successfully.']);
    }

    public function review(CropFieldLayout $cropFieldLayout): JsonResponse
    {
        return response()->json(['status' => true, 'data' => ['id' => $cropFieldLayout->id,
            ...$this->reviewSections($this->manager->snapshot($cropFieldLayout)), 'wizard' => $this->manager->wizard($cropFieldLayout)],
            'pagination' => null, 'message' => 'Field layout review retrieved successfully.']);
    }

    public function finalize(FinalizeCropFieldLayoutRequest $request, CropFieldLayout $cropFieldLayout): JsonResponse
    {
        $layout = $this->manager->finalize($cropFieldLayout, $request->validated());

        return response()->json(['status' => true, 'data' => new CropFieldLayoutResource($this->detail($layout)), 'pagination' => null, 'message' => 'Field layout saved successfully.']);
    }

    public function destroy(CropFieldLayout $cropFieldLayout): JsonResponse
    {
        if (! $this->manager->delete($cropFieldLayout)) {
            return response()->json(['status' => false, 'data' => null, 'pagination' => null, 'message' => 'Referenced field layouts cannot be deleted. Deactivate the layout instead.'], 409);
        }

        return response()->json(['status' => true, 'data' => null, 'pagination' => null, 'message' => 'Field layout deleted successfully.']);
    }

    private function detail(CropFieldLayout $layout): CropFieldLayout
    {
        $layout->load($this->relations());
        $layout->setAttribute('detail_view', true);
        $layout->setAttribute('wizard_state', $this->manager->wizard($layout));
        $layout->setAttribute('draft_view', ($layout->wizard_draft['is_edit'] ?? false) ? $this->reviewSections($this->manager->snapshot($layout)) : null);

        return $layout;
    }

    /** @return array<int|string, mixed> */
    private function relations(bool $detail = true): array
    {
        $localized = fn (string $foreignKey, string $field): callable => fn (HasMany $translations): HasMany => $translations
            ->whereIn('locale', array_unique([app()->getLocale(), config('languages.default')]))
            ->select(['id', $foreignKey, 'locale', $field]);

        return ['crops:id', 'crops.translations' => $localized('crop_id', 'name'),
            'cropVariety:id,crop_id', 'cropVariety.translations' => $localized('crop_variety_id', 'name'),
            'sowingMethod:id,crop_scope', 'sowingMethod.translations' => $localized('sowing_method_id', 'title'),
            'translations' => $detail ? fn (HasMany $translations): HasMany => $translations : $localized('crop_field_layout_id', 'title')];
    }

    private function whereLocalizedMatch(Builder $query, string $field, string $pattern): void
    {
        $query->where(function (Builder $query) use ($field, $pattern): void {
            $query->whereHas('translations', fn (Builder $translation): Builder => $translation
                ->where('locale', app()->getLocale())->whereRaw($field." LIKE ? ESCAPE '!'", [$pattern]))
                ->orWhere(fn (Builder $fallback): Builder => $fallback
                    ->whereDoesntHave('translations', fn (Builder $translation): Builder => $translation->where('locale', app()->getLocale()))
                    ->whereHas('translations', fn (Builder $translation): Builder => $translation
                        ->where('locale', config('languages.default'))->whereRaw($field." LIKE ? ESCAPE '!'", [$pattern])));
        });
    }

    /** @param array<string, mixed> $values @return array<string, mixed> */
    private function reviewSections(array $values): array
    {
        $locales = array_unique([app()->getLocale(), config('languages.default')]);
        $localized = fn (HasMany $translations): HasMany => $translations->whereIn('locale', $locales);
        $crops = Crop::query()->whereKey($values['crop_ids'])->with(['translations' => $localized])->orderBy('id')->get(['id']);
        $variety = $values['crop_variety_id'] === null ? null : CropVariety::query()->with(['translations' => $localized])->find($values['crop_variety_id']);
        $method = $values['sowing_method_id'] === null ? null : SowingMethod::query()->with(['translations' => $localized])->find($values['sowing_method_id']);
        $basic = Arr::only($values, [...CropFieldLayout::BASIC_FIELDS, 'crop_ids', 'is_active']);
        $basic['requested_is_active'] = $values['is_active'];
        $basic['crops'] = $crops->map(fn (Crop $crop): array => ['id' => $crop->id, 'name' => $crop->translatedName()])->all();
        $basic['crop_variety'] = $variety === null ? null : ['id' => $variety->id,
            'name' => $variety->translations->firstWhere('locale', app()->getLocale())?->name
                ?? $variety->translations->firstWhere('locale', config('languages.default'))?->name];
        $basic['sowing_method'] = $method === null ? null : ['id' => $method->id, 'title' => $method->translation()?->title];
        $basic['layout_type_label'] = CropFieldLayoutOptions::definition($values['layout_type'])['label'] ?? null;
        $basic['farming_type_label'] = CropFieldLayoutOptions::FARMING_TYPES[$values['farming_type']] ?? null;
        $basic['growing_system_label'] = CropFieldLayoutOptions::GROWING_SYSTEMS[$values['growing_system']] ?? null;
        $translations = [];
        foreach ($values['translations'] as $locale => $fields) {
            $translations[$locale] = ['locale' => $locale, 'language_name' => config('languages.supported.'.$locale, $locale), ...$fields];
        }

        return ['basic_details' => $basic,
            'layout_configuration' => ['layout_type' => $values['layout_type'], 'configuration' => (object) $values['configuration'],
                'configuration_version' => $values['configuration_version'], 'reference_note' => $values['reference_note'],
                'definition' => CropFieldLayoutOptions::definition($values['layout_type'])],
            'translations' => (object) $translations];
    }
}
