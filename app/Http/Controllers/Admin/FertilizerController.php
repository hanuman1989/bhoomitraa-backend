<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveFertilizerMediaRequest;
use App\Http\Requests\Admin\SaveFertilizerNutrientsRequest;
use App\Http\Requests\Admin\SaveFertilizerTranslationsRequest;
use App\Http\Requests\Admin\StoreFertilizerStepOneRequest;
use App\Http\Requests\Admin\UpdateFertilizerStatusRequest;
use App\Http\Requests\Admin\UpdateFertilizerStepOneRequest;
use App\Http\Resources\Admin\FertilizerResource;
use App\Models\Fertilizer;
use App\Models\FertilizerNutrient;
use App\Models\KnowledgeSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use PDOException;
use RuntimeException;
use Throwable;

class FertilizerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'keyword' => ['sometimes', 'nullable', 'string', 'max:150'],
            'fertilizer_type' => ['sometimes', Rule::in(Fertilizer::TYPES)],
            'form' => ['sometimes', Rule::in(Fertilizer::FORMS)],
            'is_active' => ['sometimes', 'boolean'],
            'verification_status' => ['sometimes', Rule::in(KnowledgeSource::VERIFICATION_STATUSES)],
            'setup_status' => ['sometimes', Rule::in(['draft', 'completed'])],
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        try {
            $query = Fertilizer::query()->withLocalizedName()->with($this->relations(false));
            foreach (['fertilizer_type', 'form', 'is_active', 'verification_status', 'setup_status'] as $field) {
                if (array_key_exists($field, $filters)) {
                    $query->where('fertilizers.'.$field, $filters[$field]);
                }
            }
            $keyword = trim($filters['keyword'] ?? '');
            if ($keyword !== '') {
                $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $keyword).'%';
                $query->where(function (Builder $query) use ($pattern): void {
                    $query->whereRaw("COALESCE(requested_translation.name, default_translation.name) LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("default_translation.name LIKE ? ESCAPE '!'", [$pattern]);
                    foreach (['fertilizer_code', 'slug', 'brand_name', 'manufacturer_name'] as $field) {
                        $query->orWhereRaw('fertilizers.'.$field." LIKE ? ESCAPE '!'", [$pattern]);
                    }
                });
            }
            $fertilizers = $query->orderBy('localized_name')->orderBy('fertilizers.id')
                ->paginate($request->integer('per_page', 15))->appends(Arr::except($filters, ['page']));
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to retrieve fertilizers. Please try again later.');
        }

        return response()->json([
            'status' => true, 'data' => FertilizerResource::collection($fertilizers),
            'pagination' => ['current_page' => $fertilizers->currentPage(), 'last_page' => $fertilizers->lastPage(), 'per_page' => $fertilizers->perPage(),
                'total' => $fertilizers->total(), 'from' => $fertilizers->firstItem(), 'to' => $fertilizers->lastItem(),
                'next_page_url' => $fertilizers->nextPageUrl(), 'prev_page_url' => $fertilizers->previousPageUrl()],
            'message' => 'Fertilizers retrieved successfully.',
        ]);
    }

    public function show(Fertilizer $fertilizer): JsonResponse
    {
        $fertilizer->load($this->relations());

        return response()->json(['status' => true, 'data' => new FertilizerResource($fertilizer), 'pagination' => null, 'message' => 'Fertilizer retrieved successfully.']);
    }

    public function storeStepOne(StoreFertilizerStepOneRequest $request): JsonResponse
    {
        try {
            $fertilizer = $this->saveBasicDetails($request);
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to create the fertilizer. Please try again later.');
        }

        return response()->json(['status' => true, 'data' => new FertilizerResource($fertilizer), 'pagination' => null, 'message' => 'Fertilizer basic details saved successfully.'], 201);
    }

    public function updateStepOne(UpdateFertilizerStepOneRequest $request, Fertilizer $fertilizer): JsonResponse
    {
        try {
            $fertilizer = $this->saveBasicDetails($request, $fertilizer);
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to update the fertilizer. Please try again later.');
        }

        return response()->json(['status' => true, 'data' => new FertilizerResource($fertilizer), 'pagination' => null, 'message' => 'Fertilizer basic details updated successfully.']);
    }

    private function saveBasicDetails(StoreFertilizerStepOneRequest $request, ?Fertilizer $existing = null): Fertilizer
    {
        return DB::transaction(function () use ($request, $existing): Fertilizer {
            $fertilizer = $existing === null ? new Fertilizer : Fertilizer::query()->lockForUpdate()->findOrFail($existing->id);
            $fertilizer->fill(Arr::only($request->validated(), $fertilizer->getFillable()));
            $errors = $fertilizer->basicErrors();
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
            $changed = $fertilizer->isDirty();
            $fertilizer->save();
            if ($request->exists('name')) {
                $translation = $fertilizer->translations()->updateOrCreate(['locale' => config('languages.default')], ['name' => $request->validated('name')]);
                $changed = $changed || $translation->wasRecentlyCreated || $translation->wasChanged('name');
            }
            if ($changed) {
                $fertilizer->invalidateVerification();
            }
            $fertilizer->save();

            return $fertilizer->load($this->relations());
        });
    }

    public function saveStepTwo(SaveFertilizerNutrientsRequest $request, Fertilizer $fertilizer): JsonResponse
    {
        $fertilizer = DB::transaction(function () use ($request, $fertilizer): Fertilizer {
            $fertilizer = Fertilizer::query()->lockForUpdate()->findOrFail($fertilizer->id);
            $fertilizer->load('nutrients');
            $rows = $request->validated('nutrients');
            $errors = SaveFertilizerNutrientsRequest::compositionErrors($fertilizer, $rows);
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
            $changed = false;
            $keepIds = [];
            foreach ($rows as $row) {
                $nutrient = $fertilizer->nutrients->first(fn (FertilizerNutrient $existing): bool => $existing->nutrient_code === $row['nutrient_code'] && $existing->content_unit_code === $row['content_unit_code']);
                $candidate = $nutrient === null ? new FertilizerNutrient : clone $nutrient;
                $candidate->fill($row);
                abort_if($nutrient !== null && $candidate->isDirty() && $nutrient->hasExternalReferences(), 409, 'Referenced nutrient composition cannot be rewritten.');
                $saved = $fertilizer->nutrients()->updateOrCreate(
                    ['nutrient_code' => $row['nutrient_code'], 'content_unit_code' => $row['content_unit_code']], ['content_value' => $row['content_value']]);
                $changed = $saved->wasRecentlyCreated || $saved->wasChanged('content_value') || $changed;
                $keepIds[] = $saved->id;
            }
            foreach ($fertilizer->nutrients as $nutrient) {
                if (! in_array($nutrient->id, $keepIds, true)) {
                    abort_if($nutrient->hasExternalReferences(), 409, 'Referenced nutrient composition cannot be removed.');
                    $nutrient->delete();
                    $changed = true;
                }
            }
            if ($request->exists('composition_notes')) {
                $fertilizer->composition_notes = $request->validated('composition_notes');
                $changed = $fertilizer->isDirty('composition_notes') || $changed;
            }
            if ($changed) {
                $fertilizer->invalidateVerification();
            }
            $fertilizer->completed_step = max($fertilizer->completed_step, 2);
            $fertilizer->save();

            return $fertilizer->load($this->relations());
        });

        return response()->json(['status' => true, 'data' => new FertilizerResource($fertilizer), 'pagination' => null, 'message' => 'Fertilizer nutrients saved successfully.']);
    }

    public function saveStepThree(SaveFertilizerTranslationsRequest $request, Fertilizer $fertilizer): JsonResponse
    {
        $fertilizer = DB::transaction(function () use ($request, $fertilizer): Fertilizer {
            $fertilizer = Fertilizer::query()->lockForUpdate()->findOrFail($fertilizer->id);
            abort_if($fertilizer->completed_step < 2, 409, 'Save nutrient composition before continuing to translations.');
            $values = $request->validated('translations');
            $errors = $this->translationErrors($fertilizer, $values);
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
            $changed = false;
            foreach ($values as $locale => $fields) {
                $translation = $fertilizer->translations()->updateOrCreate(['locale' => $locale], $fields);
                $changed = $translation->wasRecentlyCreated || $translation->wasChanged(['name', 'description', 'usage_instructions']) || $changed;
            }
            if ($changed) {
                $fertilizer->invalidateVerification();
            }
            $fertilizer->completed_step = max($fertilizer->completed_step, 3);
            $fertilizer->save();

            return $fertilizer->load($this->relations());
        });

        return response()->json(['status' => true, 'data' => new FertilizerResource($fertilizer), 'pagination' => null, 'message' => 'Fertilizer translations saved successfully.']);
    }

    public function saveStepFour(SaveFertilizerMediaRequest $request, Fertilizer $fertilizer): JsonResponse
    {
        $paths = [];
        try {
            $fertilizer = DB::transaction(function () use ($request, $fertilizer, &$paths): Fertilizer {
                $fertilizer = Fertilizer::query()->lockForUpdate()->findOrFail($fertilizer->id);
                abort_if($fertilizer->completed_step < 3, 409, 'Save the required translations before continuing to media and source.');
                $fertilizer->load('images');
                $plan = $this->imagePlan($fertilizer, $request->validated());
                $oldPaths = [];
                foreach ($fertilizer->images as $image) {
                    if (! in_array($image->id, $plan['retain'], true)) {
                        $oldPaths[] = $image->image_path;
                        $image->delete();
                    }
                }
                $new = [];
                foreach ($request->file('images', []) as $key => $file) {
                    $directory = 'fertilizers/'.$fertilizer->id;
                    $filename = Str::uuid().'.'.$file->guessExtension();
                    $path = $directory.'/'.$filename;
                    $paths[] = $path;
                    if (Storage::disk(config('filesystems.fertilizer_images_disk'))->putFileAs($directory, $file, $filename, ['visibility' => 'public']) === false) {
                        throw new RuntimeException('Unable to store fertilizer image.');
                    }
                    $new['new:'.$key] = $fertilizer->images()->create(['image_path' => $path, 'sort_order' => 0]);
                }
                foreach ($plan['order'] as $position => $token) {
                    $image = $new[$token] ?? $fertilizer->images->firstWhere('id', (int) substr($token, 3));
                    $image->sort_order = $position + 1;
                    if (array_key_exists($token, $request->validated('alt_texts', []))) {
                        $image->alt_text = $request->validated('alt_texts')[$token];
                    }
                    $image->save();
                }
                $fertilizer->unsetRelation('images');
                if ($request->exists('source_id')) {
                    $fertilizer->source_id = $request->validated('source_id');
                }
                $fertilizer->verification_status = $request->validated('verification_status');
                if ($request->exists('is_active')) {
                    $fertilizer->requested_is_active = $request->boolean('is_active');
                }
                $errors = $this->sourceErrors($fertilizer, lock: true);
                if ($errors !== []) {
                    throw ValidationException::withMessages($errors);
                }
                $fertilizer->is_active = $fertilizer->setup_status === 'completed' && $fertilizer->requested_is_active;
                if ($fertilizer->is_active) {
                    Gate::forUser($request->user())->authorize('review-knowledge-sources');
                    $errors = $this->completionErrors($fertilizer);
                    if ($errors !== []) {
                        throw ValidationException::withMessages($errors);
                    }
                }
                $fertilizer->completed_step = max($fertilizer->completed_step, 4);
                $fertilizer->save();
                $fertilizer->getConnection()->afterCommit(fn () => $this->deleteImageFiles($oldPaths, $fertilizer->id));

                return $fertilizer->load($this->relations());
            });
        } catch (Throwable $exception) {
            $this->deleteImageFiles($paths, $fertilizer->id);
            if ($exception instanceof QueryException || $exception instanceof PDOException) {
                return $this->databaseFailure($exception, 'Unable to save fertilizer media. Please try again later.');
            }
            throw $exception;
        }

        return response()->json(['status' => true, 'data' => new FertilizerResource($fertilizer), 'pagination' => null, 'message' => 'Fertilizer media and source saved successfully.']);
    }

    public function review(Fertilizer $fertilizer): JsonResponse
    {
        $fertilizer->load($this->relations());
        $errors = $this->completionErrors($fertilizer);
        if ($fertilizer->setup_status === 'completed') {
            $errors['setup_status'] = ['This fertilizer is already finalized.'];
        }
        $fertilizer->setAttribute('readiness_errors', $errors);

        return response()->json(['status' => true, 'data' => new FertilizerResource($fertilizer), 'pagination' => null, 'message' => 'Fertilizer review retrieved successfully.']);
    }

    public function finalize(Request $request, Fertilizer $fertilizer): JsonResponse
    {
        $unexpected = array_diff(array_keys($request->all()), ['_method']);
        if ($unexpected !== []) {
            throw ValidationException::withMessages(array_fill_keys($unexpected, ['This field is not allowed.']));
        }
        $fertilizer = DB::transaction(function () use ($request, $fertilizer): Fertilizer {
            $fertilizer = Fertilizer::query()->lockForUpdate()->findOrFail($fertilizer->id);
            abort_if($fertilizer->setup_status === 'completed', 409, 'This fertilizer is already finalized.');
            if ($fertilizer->requested_is_active) {
                Gate::forUser($request->user())->authorize('review-knowledge-sources');
            }
            $errors = $this->completionErrors($fertilizer, lock: true);
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
            $fertilizer->setup_status = 'completed';
            $fertilizer->completed_step = 5;
            $fertilizer->is_active = $fertilizer->requested_is_active;
            $fertilizer->save();

            return $fertilizer->load($this->relations());
        });

        return response()->json(['status' => true, 'data' => new FertilizerResource($fertilizer), 'pagination' => null, 'message' => 'Fertilizer setup completed successfully.']);
    }

    public function updateStatus(UpdateFertilizerStatusRequest $request, Fertilizer $fertilizer): JsonResponse
    {
        $fertilizer = DB::transaction(function () use ($request, $fertilizer): Fertilizer {
            $fertilizer = Fertilizer::query()->lockForUpdate()->findOrFail($fertilizer->id);
            abort_if($request->boolean('is_active') && $fertilizer->setup_status !== 'completed', 409, 'Complete the fertilizer wizard before activation.');
            if ($request->boolean('is_active')) {
                $fertilizer->requested_is_active = true;
                $errors = $this->completionErrors($fertilizer, lock: true);
                if ($errors !== []) {
                    throw ValidationException::withMessages($errors);
                }
            }
            $fertilizer->requested_is_active = $request->boolean('is_active');
            $fertilizer->is_active = $request->boolean('is_active');
            $fertilizer->save();

            return $fertilizer->load($this->relations());
        });

        return response()->json(['status' => true, 'data' => new FertilizerResource($fertilizer), 'pagination' => null, 'message' => 'Fertilizer status updated successfully.']);
    }

    public function destroy(Fertilizer $fertilizer): JsonResponse
    {
        DB::transaction(function () use ($fertilizer): void {
            $fertilizer = Fertilizer::query()->lockForUpdate()->findOrFail($fertilizer->id);
            abort_if($fertilizer->is_active || $fertilizer->hasExternalReferences(), 409, 'Active or referenced fertilizers cannot be deleted. Deactivate the fertilizer instead.');
            $paths = $fertilizer->images()->pluck('image_path')->all();
            $fertilizer->delete();
            $fertilizer->getConnection()->afterCommit(fn () => $this->deleteImageFiles($paths, $fertilizer->id));
        });

        return response()->json(['status' => true, 'data' => null, 'pagination' => null, 'message' => 'Fertilizer deleted successfully.']);
    }

    /** @return array<int|string, mixed> */
    private function relations(bool $detail = true): array
    {
        $names = static fn (HasMany $translations): HasMany => $translations
            ->whereIn('locale', array_unique([app()->getLocale(), config('languages.default')]))
            ->select(['id', $translations->getForeignKeyName(), 'locale', 'name']);
        $relations = ['nutrients', 'primaryImage'];
        if ($detail) {
            $relations[] = 'translations';
            $relations[] = 'images';
            $relations[] = 'knowledgeSource:id,title,publisher,source_type,source_url,reference_identifier,is_active,verification_status';
            $relations['knowledgeSource.translations'] = static fn (HasMany $translations): HasMany => $translations
                ->whereIn('locale', array_unique([app()->getLocale(), config('languages.default')]))
                ->select(['id', 'knowledge_source_id', 'locale', 'title']);
        } else {
            $relations['translations'] = $names;
        }

        return $relations;
    }

    /** @param array<string, array<string, mixed>> $input @return array<string, list<string>> */
    private function translationErrors(Fertilizer $fertilizer, array $input = []): array
    {
        $fertilizer->loadMissing('translations');
        $names = $fertilizer->translations->pluck('name', 'locale')->all();
        foreach ($input as $locale => $fields) {
            if (array_key_exists('name', $fields)) {
                $names[$locale] = $fields['name'];
            }
        }
        $errors = [];
        foreach (Fertilizer::requiredLocales() as $locale) {
            if (blank($names[$locale] ?? null)) {
                $errors['translations.'.$locale.'.name'] = ['A nonempty '.$locale.' fertilizer name is required.'];
            }
        }

        return $errors;
    }

    /** @return array<string, list<string>> */
    private function sourceErrors(Fertilizer $fertilizer, bool $lock = false): array
    {
        if ($fertilizer->source_id === null) {
            return [];
        }
        $source = KnowledgeSource::query()->whereKey($fertilizer->source_id)->when($lock, fn (Builder $query): Builder => $query->lockForUpdate())->first();
        if ($source === null || ($fertilizer->verification_status === 'verified' && (! $source->is_active || $source->verification_status !== 'verified' || ! $source->hasUsableReference()))) {
            return ['source_id' => ['Select an eligible source; verification requires an active, verified source with a usable reference.']];
        }

        return [];
    }

    /** @return array<string, list<string>> */
    private function completionErrors(Fertilizer $fertilizer, bool $lock = false): array
    {
        $fertilizer->loadMissing(['translations', 'images', 'nutrients']);
        $values = [...$fertilizer->only($fertilizer->getFillable()), 'name' => $fertilizer->translations->firstWhere('locale', config('languages.default'))?->name];
        $errors = Validator::make($values, StoreFertilizerStepOneRequest::fieldRules($fertilizer))->errors()->messages();
        $errors = [...$errors, ...$fertilizer->basicErrors(), ...$this->translationErrors($fertilizer), ...$this->sourceErrors($fertilizer, $lock)];
        $rows = $fertilizer->nutrients->map(fn (FertilizerNutrient $row): array => $row->only(['nutrient_code', 'content_value', 'content_unit_code']))->all();
        $nutrientErrors = Validator::make(['nutrients' => $rows], SaveFertilizerNutrientsRequest::fieldRules())->errors()->messages();
        $errors = [...$errors, ...($nutrientErrors !== [] ? $nutrientErrors : SaveFertilizerNutrientsRequest::compositionErrors($fertilizer, $rows))];
        if (! in_array($fertilizer->verification_status, KnowledgeSource::VERIFICATION_STATUSES, true)) {
            $errors['verification_status'] = ['Select a valid verification status.'];
        }
        if ($fertilizer->completed_step < 4) {
            $errors['completed_step'] = ['Save all four wizard steps before finalizing.'];
        }
        if ($fertilizer->images->count() > Fertilizer::MAX_IMAGES) {
            $errors['images'] = ['A fertilizer may have at most eight images.'];
        }
        foreach ($fertilizer->images->values() as $index => $image) {
            if ($image->sort_order !== $index + 1 || ! str_starts_with($image->image_path, 'fertilizers/'.$fertilizer->id.'/')
                || str_contains($image->image_path, '..') || ! Storage::disk(config('filesystems.fertilizer_images_disk'))->exists($image->image_path)) {
                $errors['images'] = ['Saved images must exist and have valid consecutive ordering.'];
            }
        }

        return $errors;
    }

    /** @param array<string, mixed> $input @return array{retain: list<int>, order: list<string>} */
    private function imagePlan(Fertilizer $fertilizer, array $input): array
    {
        $owned = $fertilizer->images->modelKeys();
        $retain = array_map('intval', $input['retain_image_ids'] ?? $owned);
        $remove = array_map('intval', $input['remove_image_ids'] ?? []);
        $errors = [];
        if (array_diff($retain, $owned) !== []) {
            $errors['retain_image_ids'] = ['Every retained image ID must belong to this fertilizer.'];
        }
        if (array_diff($remove, $owned) !== []) {
            $errors['remove_image_ids'] = ['Every removed image ID must belong to this fertilizer.'];
        }
        if (isset($input['retain_image_ids']) && array_intersect($retain, $remove) !== []) {
            $errors['remove_image_ids'] = ['An image cannot be both retained and removed.'];
        }
        $retain = array_values(array_diff(array_intersect($owned, $retain), $remove));
        $tokens = [...array_map(fn (int $id): string => 'id:'.$id, $retain), ...array_map(fn (int|string $key): string => 'new:'.$key, array_keys($input['images'] ?? []))];
        $order = $input['image_order'] ?? $tokens;
        if (count($tokens) > Fertilizer::MAX_IMAGES) {
            $errors['images'] = ['A fertilizer may have at most eight images.'];
        }
        if (count($tokens) !== count($order) || array_diff($tokens, $order) !== [] || array_diff($order, $tokens) !== []) {
            $errors['image_order'] = ['Order must contain every retained and newly uploaded image exactly once.'];
        }
        if (array_diff(array_keys($input['alt_texts'] ?? []), $tokens) !== []) {
            $errors['alt_texts'] = ['Alt text must refer to a retained or newly uploaded image.'];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['retain' => $retain, 'order' => $order];
    }

    /** @param list<string> $paths */
    private function deleteImageFiles(array $paths, int $fertilizerId): void
    {
        foreach ($paths as $path) {
            if (! str_starts_with($path, 'fertilizers/'.$fertilizerId.'/') || str_contains($path, '..')) {
                Log::warning('Fertilizer image cleanup skipped an invalid path.', ['fertilizer_id' => $fertilizerId]);

                continue;
            }
            try {
                if (! Storage::disk(config('filesystems.fertilizer_images_disk'))->delete($path)) {
                    Log::warning('Fertilizer image cleanup failed.', ['fertilizer_id' => $fertilizerId]);
                }
            } catch (Throwable $exception) {
                Log::warning('Fertilizer image cleanup failed.', ['fertilizer_id' => $fertilizerId, 'exception_type' => $exception::class]);
            }
        }
    }
}
