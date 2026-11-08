<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveSeedMediaRequest;
use App\Http\Requests\Admin\SaveSeedTranslationsRequest;
use App\Http\Requests\Admin\StoreSeedStepOneRequest;
use App\Http\Requests\Admin\UpdateSeedStatusRequest;
use App\Http\Requests\Admin\UpdateSeedStepOneRequest;
use App\Http\Resources\Admin\SeedResource;
use App\Models\KnowledgeSource;
use App\Models\Seed;
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
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\ValidationException;
use PDOException;
use RuntimeException;
use Throwable;

class SeedController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'keyword' => ['sometimes', 'nullable', 'string', 'max:150'],
            'crop_id' => ['sometimes', 'integer', 'min:1', Rule::exists('crops', 'id')],
            'crop_variety_id' => ['sometimes', 'integer', 'min:1', Rule::exists('crop_varieties', 'id')->when($request->filled('crop_id'), fn (Exists $rule): Exists => $rule->where('crop_id', $request->integer('crop_id')))],
            'material_type' => ['sometimes', Rule::in(Seed::MATERIAL_TYPES)],
            'is_active' => ['sometimes', 'boolean'],
            'verification_status' => ['sometimes', Rule::in(KnowledgeSource::VERIFICATION_STATUSES)],
            'setup_status' => ['sometimes', Rule::in(['draft', 'completed'])],
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        if (isset($filters['crop_variety_id']) && ! isset($filters['crop_id'])) {
            throw ValidationException::withMessages(['crop_id' => ['Select a crop before filtering by variety.']]);
        }
        try {
            $query = Seed::query()->withLocalizedName()->with($this->relations(false));
            foreach (['crop_id', 'crop_variety_id', 'material_type', 'is_active', 'verification_status', 'setup_status'] as $field) {
                if (array_key_exists($field, $filters)) {
                    $query->where('seeds.'.$field, $filters[$field]);
                }
            }
            $keyword = trim($filters['keyword'] ?? '');
            if ($keyword !== '') {
                $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $keyword).'%';
                $query->where(function (Builder $query) use ($pattern): void {
                    $query->whereRaw("COALESCE(requested_translation.name, default_translation.name) LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("default_translation.name LIKE ? ESCAPE '!'", [$pattern]);
                    foreach (['sku', 'slug', 'brand_name', 'producer_name'] as $field) {
                        $query->orWhereRaw('seeds.'.$field." LIKE ? ESCAPE '!'", [$pattern]);
                    }
                });
            }
            $seeds = $query->orderBy('localized_name')->orderBy('seeds.id')
                ->paginate($request->integer('per_page', 15))->appends(Arr::except($filters, ['page']));
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to retrieve seeds. Please try again later.');
        }

        return response()->json([
            'status' => true, 'data' => SeedResource::collection($seeds),
            'pagination' => ['current_page' => $seeds->currentPage(), 'last_page' => $seeds->lastPage(), 'per_page' => $seeds->perPage(),
                'total' => $seeds->total(), 'from' => $seeds->firstItem(), 'to' => $seeds->lastItem(),
                'next_page_url' => $seeds->nextPageUrl(), 'prev_page_url' => $seeds->previousPageUrl()],
            'message' => 'Seeds retrieved successfully.',
        ]);
    }

    public function show(Seed $seed): JsonResponse
    {
        $seed->load($this->relations());

        return response()->json(['status' => true, 'data' => new SeedResource($seed), 'pagination' => null, 'message' => 'Seed retrieved successfully.']);
    }

    public function storeStepOne(StoreSeedStepOneRequest $request): JsonResponse
    {
        try {
            $seed = $this->saveBasicDetails($request);
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to create the seed. Please try again later.');
        }

        return response()->json(['status' => true, 'data' => new SeedResource($seed), 'pagination' => null, 'message' => 'Seed basic details saved successfully.'], 201);
    }

    public function updateStepOne(UpdateSeedStepOneRequest $request, Seed $seed): JsonResponse
    {
        try {
            $seed = $this->saveBasicDetails($request, $seed);
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to update the seed. Please try again later.');
        }

        return response()->json(['status' => true, 'data' => new SeedResource($seed), 'pagination' => null, 'message' => 'Seed basic details updated successfully.']);
    }

    private function saveBasicDetails(StoreSeedStepOneRequest $request, ?Seed $existing = null): Seed
    {
        return DB::transaction(function () use ($request, $existing): Seed {
            $seed = $existing === null ? new Seed : Seed::query()->lockForUpdate()->findOrFail($existing->id);
            $seed->fill(Arr::only($request->validated(), $seed->getFillable()));
            if ($seed->material_type !== 'seed') {
                foreach (Seed::SEED_ONLY_FIELDS as $field) {
                    $seed->{$field} = null;
                }
            }
            $errors = $seed->basicErrors(lock: true);
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
            $changed = $seed->isDirty();
            $seed->save();
            if ($request->exists('name')) {
                $translation = $seed->translations()->updateOrCreate(['locale' => config('languages.default')], ['name' => $request->validated('name')]);
                $changed = $changed || $translation->wasRecentlyCreated || $translation->wasChanged('name');
            }
            if ($changed) {
                $seed->invalidateVerification();
            }
            $seed->save();

            return $seed->load($this->relations());
        });
    }

    public function saveStepTwo(SaveSeedTranslationsRequest $request, Seed $seed): JsonResponse
    {
        $seed = DB::transaction(function () use ($request, $seed): Seed {
            $seed = Seed::query()->lockForUpdate()->findOrFail($seed->id);
            $values = $request->validated('translations');
            $errors = $this->translationErrors($seed, $values);
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
            $changed = false;
            foreach ($values as $locale => $fields) {
                $translation = $seed->translations()->updateOrCreate(['locale' => $locale], $fields);
                $changed = $translation->wasRecentlyCreated || $translation->wasChanged(['name', 'description', 'planting_instructions']) || $changed;
            }
            if ($changed) {
                $seed->invalidateVerification();
            }
            $seed->completed_step = max($seed->completed_step, 2);
            $seed->save();

            return $seed->load($this->relations());
        });

        return response()->json(['status' => true, 'data' => new SeedResource($seed), 'pagination' => null, 'message' => 'Seed translations saved successfully.']);
    }

    public function saveStepThree(SaveSeedMediaRequest $request, Seed $seed): JsonResponse
    {
        $paths = [];
        try {
            $seed = DB::transaction(function () use ($request, $seed, &$paths): Seed {
                $seed = Seed::query()->lockForUpdate()->findOrFail($seed->id);
                abort_if($seed->completed_step < 2, 409, 'Save the required translations before continuing to media and source.');
                $seed->load('images');
                $plan = $this->imagePlan($seed, $request->validated());
                $oldPaths = [];
                foreach ($seed->images as $image) {
                    if (! in_array($image->id, $plan['retain'], true)) {
                        $oldPaths[] = $image->image_path;
                        $image->delete();
                    }
                }
                $new = [];
                foreach ($request->file('images', []) as $key => $file) {
                    $directory = 'seeds/'.$seed->id;
                    $filename = Str::uuid().'.'.$file->guessExtension();
                    $path = $directory.'/'.$filename;
                    $paths[] = $path;
                    if (Storage::disk(config('filesystems.seed_images_disk'))->putFileAs($directory, $file, $filename, ['visibility' => 'public']) === false) {
                        throw new RuntimeException('Unable to store seed image.');
                    }
                    $new['new:'.$key] = $seed->images()->create(['image_path' => $path, 'sort_order' => 0]);
                }
                foreach ($plan['order'] as $position => $token) {
                    $image = $new[$token] ?? $seed->images->firstWhere('id', (int) substr($token, 3));
                    $image->sort_order = $position + 1;
                    if (array_key_exists($token, $request->validated('alt_texts', []))) {
                        $image->alt_text = $request->validated('alt_texts')[$token];
                    }
                    $image->save();
                }
                $seed->unsetRelation('images');
                if ($request->exists('source_id')) {
                    $seed->source_id = $request->validated('source_id');
                }
                $seed->verification_status = $request->validated('verification_status');
                if ($request->exists('is_active')) {
                    $seed->requested_is_active = $request->boolean('is_active');
                }
                $errors = $this->sourceErrors($seed, lock: true);
                if ($errors !== []) {
                    throw ValidationException::withMessages($errors);
                }
                $seed->is_active = $seed->setup_status === 'completed' && $seed->requested_is_active && $seed->verification_status === 'verified';
                if ($seed->is_active) {
                    Gate::forUser($request->user())->authorize('review-knowledge-sources');
                    $errors = $this->completionErrors($seed);
                    if ($errors !== []) {
                        throw ValidationException::withMessages($errors);
                    }
                }
                $seed->completed_step = max($seed->completed_step, 3);
                $seed->save();
                $seed->getConnection()->afterCommit(fn () => $this->deleteImageFiles($oldPaths, $seed->id));

                return $seed->load($this->relations());
            });
        } catch (Throwable $exception) {
            $this->deleteImageFiles($paths, $seed->id);
            if ($exception instanceof QueryException || $exception instanceof PDOException) {
                return $this->databaseFailure($exception, 'Unable to save seed media. Please try again later.');
            }
            throw $exception;
        }

        return response()->json(['status' => true, 'data' => new SeedResource($seed), 'pagination' => null, 'message' => 'Seed media and source saved successfully.']);
    }

    public function review(Seed $seed): JsonResponse
    {
        $seed->load($this->relations());
        $seed->setAttribute('readiness_errors', $this->completionErrors($seed));

        return response()->json(['status' => true, 'data' => new SeedResource($seed), 'pagination' => null, 'message' => 'Seed review retrieved successfully.']);
    }

    public function finalize(Request $request, Seed $seed): JsonResponse
    {
        $unexpected = array_diff(array_keys($request->all()), ['_method']);
        if ($unexpected !== []) {
            throw ValidationException::withMessages(array_fill_keys($unexpected, ['This field is not allowed.']));
        }
        $seed = DB::transaction(function () use ($request, $seed): Seed {
            $seed = Seed::query()->lockForUpdate()->findOrFail($seed->id);
            if ($seed->requested_is_active) {
                Gate::forUser($request->user())->authorize('review-knowledge-sources');
            }
            $errors = $this->completionErrors($seed, lock: true);
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
            $seed->setup_status = 'completed';
            $seed->completed_step = 4;
            $seed->is_active = $seed->requested_is_active;
            $seed->save();

            return $seed->load($this->relations());
        });

        return response()->json(['status' => true, 'data' => new SeedResource($seed), 'pagination' => null, 'message' => 'Seed setup completed successfully.']);
    }

    public function updateStatus(UpdateSeedStatusRequest $request, Seed $seed): JsonResponse
    {
        $seed = DB::transaction(function () use ($request, $seed): Seed {
            $seed = Seed::query()->lockForUpdate()->findOrFail($seed->id);
            abort_if($request->boolean('is_active') && $seed->setup_status !== 'completed', 409, 'Complete the seed wizard before activation.');
            if ($request->boolean('is_active')) {
                $seed->requested_is_active = true;
                $errors = $this->completionErrors($seed, lock: true);
                if ($errors !== []) {
                    throw ValidationException::withMessages($errors);
                }
            }
            $seed->requested_is_active = $request->boolean('is_active');
            $seed->is_active = $request->boolean('is_active');
            $seed->save();

            return $seed->load($this->relations());
        });

        return response()->json(['status' => true, 'data' => new SeedResource($seed), 'pagination' => null, 'message' => 'Seed status updated successfully.']);
    }

    public function destroy(Seed $seed): JsonResponse
    {
        DB::transaction(function () use ($seed): void {
            $seed = Seed::query()->lockForUpdate()->findOrFail($seed->id);
            abort_if($seed->is_active || $seed->hasExternalReferences(), 409, 'Active or referenced seeds cannot be deleted. Deactivate the seed instead.');
            $seed->delete();
        });

        return response()->json(['status' => true, 'data' => null, 'pagination' => null, 'message' => 'Seed deleted successfully.']);
    }

    /** @return array<int|string, mixed> */
    private function relations(bool $detail = true): array
    {
        $names = static fn (HasMany $translations): HasMany => $translations
            ->whereIn('locale', array_unique([app()->getLocale(), config('languages.default')]))
            ->select(['id', $translations->getForeignKeyName(), 'locale', 'name']);
        $relations = ['crop.translations' => $names, 'cropVariety.translations' => $names, 'primaryImage',
            'knowledgeSource:id,title,publisher,source_type,source_url,reference_identifier,language,is_active,verification_status',
            'knowledgeSource.translations' => static fn (HasMany $translations): HasMany => $translations
                ->whereIn('locale', array_unique([app()->getLocale(), config('languages.default')]))
                ->select(['id', 'knowledge_source_id', 'locale', 'title'])];
        if ($detail) {
            $relations[] = 'translations';
            $relations[] = 'images';
        } else {
            $relations['translations'] = $names;
        }

        return $relations;
    }

    /** @param array<string, array<string, mixed>> $input @return array<string, list<string>> */
    private function translationErrors(Seed $seed, array $input = []): array
    {
        $seed->loadMissing('translations');
        $names = $seed->translations->pluck('name', 'locale')->all();
        foreach ($input as $locale => $fields) {
            if (array_key_exists('name', $fields)) {
                $names[$locale] = $fields['name'];
            }
        }
        $errors = [];
        foreach (Seed::requiredLocales() as $locale) {
            if (blank($names[$locale] ?? null)) {
                $errors['translations.'.$locale.'.name'] = ['A nonempty '.$locale.' seed name is required.'];
            }
        }

        return $errors;
    }

    /** @return array<string, list<string>> */
    private function sourceErrors(Seed $seed, bool $lock = false): array
    {
        if ($seed->source_id === null) {
            return $seed->verification_status === 'verified' ? ['source_id' => ['Verification requires an active, verified source with a usable reference.']] : [];
        }
        $source = KnowledgeSource::query()->whereKey($seed->source_id)->when($lock, fn (Builder $query): Builder => $query->lockForUpdate())->first();
        if ($source === null || ($seed->verification_status === 'verified' && (! $source->is_active || $source->verification_status !== 'verified' || ! $source->hasUsableReference()))) {
            return ['source_id' => ['Select an eligible source; verification requires an active, verified source with a usable reference.']];
        }

        return [];
    }

    /** @return array<string, list<string>> */
    private function completionErrors(Seed $seed, bool $lock = false): array
    {
        $seed->loadMissing(['translations', 'images']);
        $values = [...$seed->only($seed->getFillable()), 'name' => $seed->translations->firstWhere('locale', config('languages.default'))?->name];
        $errors = Validator::make($values, StoreSeedStepOneRequest::fieldRules($seed))->errors()->messages();
        $errors = [...$errors, ...$seed->basicErrors($lock), ...$this->translationErrors($seed), ...$this->sourceErrors($seed, $lock)];
        if (! in_array($seed->verification_status, KnowledgeSource::VERIFICATION_STATUSES, true)) {
            $errors['verification_status'] = ['Select a valid verification status.'];
        }
        if ($seed->completed_step < 3) {
            $errors['completed_step'] = ['Save all three wizard steps before finalizing.'];
        }
        if ($seed->requested_is_active && $seed->verification_status !== 'verified') {
            $errors['is_active'] = ['Only verified seeds may be published.'];
        }
        if ($seed->images->count() > Seed::MAX_IMAGES) {
            $errors['images'] = ['A seed may have at most eight images.'];
        }
        foreach ($seed->images->values() as $index => $image) {
            if ($image->sort_order !== $index + 1 || ! str_starts_with($image->image_path, 'seeds/'.$seed->id.'/')
                || str_contains($image->image_path, '..') || ! Storage::disk(config('filesystems.seed_images_disk'))->exists($image->image_path)) {
                $errors['images'] = ['Saved images must exist and have valid consecutive ordering.'];
            }
        }

        return $errors;
    }

    /** @param array<string, mixed> $input @return array{retain: list<int>, order: list<string>} */
    private function imagePlan(Seed $seed, array $input): array
    {
        $owned = $seed->images->modelKeys();
        $retain = array_map('intval', $input['retain_image_ids'] ?? $owned);
        $remove = array_map('intval', $input['remove_image_ids'] ?? []);
        $errors = [];
        if (array_diff($retain, $owned) !== []) {
            $errors['retain_image_ids'] = ['Every retained image ID must belong to this seed.'];
        }
        if (array_diff($remove, $owned) !== []) {
            $errors['remove_image_ids'] = ['Every removed image ID must belong to this seed.'];
        }
        if (isset($input['retain_image_ids']) && array_intersect($retain, $remove) !== []) {
            $errors['remove_image_ids'] = ['An image cannot be both retained and removed.'];
        }
        $retain = array_values(array_diff(array_intersect($owned, $retain), $remove));
        $tokens = [...array_map(fn (int $id): string => 'id:'.$id, $retain), ...array_map(fn (int|string $key): string => 'new:'.$key, array_keys($input['images'] ?? []))];
        $order = $input['image_order'] ?? $tokens;
        if (count($tokens) > Seed::MAX_IMAGES) {
            $errors['images'] = ['A seed may have at most eight images.'];
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
    private function deleteImageFiles(array $paths, int $seedId): void
    {
        foreach ($paths as $path) {
            try {
                if (! Storage::disk(config('filesystems.seed_images_disk'))->delete($path)) {
                    Log::warning('Seed image cleanup failed.', ['seed_id' => $seedId]);
                }
            } catch (Throwable $exception) {
                Log::warning('Seed image cleanup failed.', ['seed_id' => $seedId, 'exception_type' => $exception::class]);
            }
        }
    }
}
