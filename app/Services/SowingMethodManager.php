<?php

namespace App\Services;

use App\Http\Requests\Admin\SaveSowingMethodTranslationsRequest;
use App\Http\Requests\Admin\StoreSowingMethodRequest;
use App\Models\Crop;
use App\Models\CropCategory;
use App\Models\CropFieldLayout;
use App\Models\SowingMethod;
use App\Models\SowingMethodTranslation;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class SowingMethodManager
{
    /** @var array<string, array{connection: string, level: int, method_id: int}> */
    private array $pendingUploads = [];

    /** @param array<string, mixed> $input */
    public function saveBasicDetails(array $input, ?SowingMethod $existing = null): SowingMethod
    {
        return DB::transaction(function () use ($input, $existing): SowingMethod {
            $method = $existing === null ? new SowingMethod : SowingMethod::query()->lockForUpdate()->findOrFail($existing->id);
            if ($method->exists && isset($input['code']) && $input['code'] !== $method->code && $this->hasReferences($method->code)) {
                throw ValidationException::withMessages(['code' => ['A referenced method code cannot be changed. Deactivate the method instead.']]);
            }
            $cropIds = array_map('intval', $input['crop_ids'] ?? ($method->exists ? $method->crops()->allRelatedIds()->all() : []));
            if (($input['crop_scope'] ?? $method->crop_scope) === 'all') {
                if (! empty($input['crop_ids'])) {
                    throw ValidationException::withMessages(['crop_ids' => ['All-crops methods must not contain selected crops.']]);
                }
                $cropIds = [];
            }
            $method->fill(Arr::only($input, $method->getFillable()));
            $errors = $this->cropErrors($method, $cropIds, complete: false, lock: true);
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
            $method->save();
            $method->crops()->sync($cropIds);
            $translation = Arr::only($input, ['title', 'short_description']);
            if ($translation !== []) {
                $method->translations()->updateOrCreate(['locale' => config('languages.default')], $translation);
            }
            if ($method->is_active) {
                $this->ensureComplete($method);
            }

            return $method;
        }, attempts: 3);
    }

    /** @param array<string, array<string, mixed>> $translations */
    public function saveTranslations(SowingMethod $method, array $translations): SowingMethod
    {
        return DB::transaction(function () use ($method, $translations): SowingMethod {
            $method = SowingMethod::query()->lockForUpdate()->findOrFail($method->id);
            $method->load('translations');
            foreach ($translations as $locale => $fields) {
                $existing = $method->translations->firstWhere('locale', $locale);
                if ($existing === null && blank($fields['title'] ?? null)) {
                    throw ValidationException::withMessages(['translations.'.$locale.'.title' => ['A title is required when adding a translation.']]);
                }
                $method->translations()->updateOrCreate(['locale' => $locale], $fields);
            }
            $method->unsetRelation('translations');
            if ($method->is_active) {
                $this->ensureComplete($method);
            }
            $method->touch();

            return $method;
        }, attempts: 3);
    }

    public function publish(SowingMethod $method, bool $active, bool $finalize = false): SowingMethod
    {
        return DB::transaction(function () use ($method, $active, $finalize): SowingMethod {
            $method = SowingMethod::query()->lockForUpdate()->findOrFail($method->id);
            if ($active || $finalize) {
                $this->ensureComplete($method);
            }
            $method->is_active = $active;
            $method->save();

            return $method;
        }, attempts: 3);
    }

    /** @return array<string, list<string>> */
    public function completionErrors(SowingMethod $method, bool $lock = false): array
    {
        $method->loadMissing(['translations', 'crops', 'images']);
        $values = [...$method->only($method->getFillable()),
            'title' => $method->translation(config('languages.default'))?->title,
            'translations' => $method->translations->mapWithKeys(fn (SowingMethodTranslation $row): array => [$row->locale => $row->only(SowingMethod::TRANSLATED_FIELDS)])->all()];
        $errors = Validator::make($values, [
            ...StoreSowingMethodRequest::fieldRules($method),
            ...SaveSowingMethodTranslationsRequest::fieldRules(complete: true),
        ])->errors()->messages();
        $errors = [...$errors, ...$this->cropErrors($method, $method->crops->modelKeys(), complete: true, lock: $lock)];
        if ($method->images->count() > SowingMethod::MAX_IMAGES) {
            $errors['images'] = ['A sowing method may have at most eight images.'];
        }
        foreach ($method->images->values() as $index => $image) {
            if ($image->sort_order !== $index + 1 || ! $this->safeImagePath($image->image, $method->id)
                || ! Storage::disk(config('filesystems.sowing_method_images_disk'))->exists($image->image)) {
                $errors['images'] = ['Saved images must exist and have valid consecutive ordering.'];
            }
        }

        return $errors;
    }

    private function ensureComplete(SowingMethod $method): void
    {
        $errors = $this->completionErrors($method, lock: true);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @param list<int> $cropIds @return array<string, list<string>> */
    private function cropErrors(SowingMethod $method, array $cropIds, bool $complete, bool $lock): array
    {
        if ($method->crop_scope === 'all') {
            return $cropIds === [] ? [] : ['crop_ids' => ['All-crops methods must not contain selected crops.']];
        }
        if ($cropIds === []) {
            return $complete ? ['crop_ids' => ['Select at least one eligible crop for a specific-crops method.']] : [];
        }
        if ($lock) {
            $crops = Crop::query()->whereKey($cropIds)->orderBy('id')->lockForUpdate()->get(['id', 'category_id']);
            CropCategory::query()->whereKey($crops->pluck('category_id')->unique())->orderBy('id')->lockForUpdate()->get(['id']);
        }
        $eligible = Crop::query()->published()->whereKey($cropIds)->pluck('crops.id')->all();

        return count($eligible) === count($cropIds) ? [] : ['crop_ids' => ['Select eligible active crops with active categories and required translations.']];
    }

    /** Lock current reference rows and JSON history while the caller holds the master lock. */
    private function hasReferences(string $code): bool
    {
        foreach (['farm_crop_cycles', 'crop_seed_rates'] as $table) {
            if (DB::table($table)->where('sowing_method', $code)->lockForUpdate()->first(['id']) !== null) {
                return true;
            }
        }
        foreach (['farm_crop_cycles' => 'planning_snapshot', 'farm_crop_edit_drafts' => 'draft_data'] as $table => $column) {
            foreach (DB::table($table)->select(['id', $column])->orderBy('id')->lockForUpdate()->lazyById(100) as $row) {
                $data = json_decode($row->{$column} ?? 'null', true);
                if (is_array($data) && $this->containsReference($data, $code)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param array<string|int, mixed> $data */
    private function containsReference(array $data, string $code): bool
    {
        foreach ($data as $key => $value) {
            if (($key === 'sowing_method' && $value === $code) || (is_array($value) && $this->containsReference($value, $code))) {
                return true;
            }
        }

        return false;
    }

    public function delete(SowingMethod $method): bool
    {
        return DB::transaction(function () use ($method): bool {
            $method = SowingMethod::query()->lockForUpdate()->findOrFail($method->id);
            if ($this->hasReferences($method->code) || CropFieldLayout::query()->where('sowing_method_id', $method->id)->exists()) {
                return false;
            }
            $paths = $method->images()->pluck('image')->all();
            $method->delete();
            DB::afterCommit(fn () => $this->deleteImageFiles($paths, $method->id));

            return true;
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string|int, UploadedFile>  $files
     */
    public function saveImages(SowingMethod $method, array $input, array $files): SowingMethod
    {
        $newPaths = [];
        try {
            return DB::transaction(function () use ($method, $input, $files, &$newPaths): SowingMethod {
                $method = SowingMethod::query()->lockForUpdate()->findOrFail($method->id);
                $method->load('images');
                $plan = $this->imagePlan($method, $input, array_keys($files));
                $oldPaths = [];
                foreach ($method->images as $image) {
                    if (! in_array($image->id, $plan['retain'], true)) {
                        $oldPaths[] = $image->image;
                        $image->delete();
                    }
                }
                $newImages = [];
                foreach ($files as $key => $file) {
                    $directory = 'sowing-methods/'.$method->id;
                    $filename = Str::uuid().'.'.$file->guessExtension();
                    $path = $directory.'/'.$filename;
                    $newPaths[] = $path;
                    $this->pendingUploads[$path] = ['connection' => $method->getConnection()->getName(),
                        'level' => $method->getConnection()->transactionLevel(), 'method_id' => $method->id];
                    if (Storage::disk(config('filesystems.sowing_method_images_disk'))->putFileAs($directory, $file, $filename, ['visibility' => 'public']) === false) {
                        throw new RuntimeException('Unable to store sowing method image.');
                    }
                    $newImages['new:'.$key] = $method->images()->create(['image' => $path, 'sort_order' => 0]);
                }
                foreach ($plan['order'] as $index => $token) {
                    $image = $newImages[$token] ?? $method->images->firstWhere('id', (int) substr($token, 3));
                    $image->sort_order = $index + 1;
                    $image->save();
                }
                $method->unsetRelation('images');
                if ($method->is_active) {
                    $this->ensureComplete($method);
                }
                $method->touch();
                DB::afterCommit(fn () => $this->deleteImageFiles($oldPaths, $method->id));

                return $method;
            });
        } catch (Throwable $exception) {
            $this->deleteImageFiles($newPaths, $method->id);
            foreach ($newPaths as $path) {
                unset($this->pendingUploads[$path]);
            }
            throw $exception;
        }
    }

    /** Follow the existing category upload compensation pattern across nested transactions. */
    public function committed(TransactionCommitted $event): void
    {
        $level = $event->connection->transactionLevel();
        foreach ($this->pendingUploads as $path => $upload) {
            if ($upload['connection'] !== $event->connectionName) {
                continue;
            }
            if ($level === 0) {
                unset($this->pendingUploads[$path]);
            } else {
                $this->pendingUploads[$path]['level'] = min($upload['level'], $level);
            }
        }
    }

    public function rolledBack(TransactionRolledBack $event): void
    {
        foreach ($this->pendingUploads as $path => $upload) {
            if ($upload['connection'] === $event->connectionName && $upload['level'] > $event->connection->transactionLevel()) {
                $this->deleteImageFiles([$path], $upload['method_id']);
                unset($this->pendingUploads[$path]);
            }
        }
    }

    /** @param array<string, mixed> $input @param list<string|int> $uploadKeys @return array{retain: list<int>, order: list<string>} */
    private function imagePlan(SowingMethod $method, array $input, array $uploadKeys): array
    {
        $owned = $method->images->modelKeys();
        $retain = array_map('intval', $input['retain_image_ids'] ?? $owned);
        $remove = array_map('intval', $input['remove_image_ids'] ?? []);
        $errors = [];
        if (array_diff($retain, $owned) !== []) {
            $errors['retain_image_ids'] = ['Every retained image ID must belong to this sowing method.'];
        }
        if (array_diff($remove, $owned) !== [] || (isset($input['retain_image_ids']) && array_intersect($retain, $remove) !== [])) {
            $errors['remove_image_ids'] = ['Removed images must belong to this method and cannot also be retained.'];
        }
        $retain = array_values(array_diff(array_intersect($owned, $retain), $remove));
        $tokens = [...array_map(fn (int $id): string => 'id:'.$id, $retain),
            ...array_map(fn (int|string $key): string => 'new:'.$key, $uploadKeys)];
        $order = $input['image_order'] ?? $tokens;
        if (count($tokens) > SowingMethod::MAX_IMAGES) {
            $errors['images'] = ['A sowing method may have at most eight images.'];
        }
        if (count($tokens) !== count($order) || array_diff($tokens, $order) !== [] || array_diff($order, $tokens) !== []) {
            $errors['image_order'] = ['Order must contain every retained and newly uploaded image exactly once.'];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['retain' => $retain, 'order' => $order];
    }

    private function safeImagePath(string $path, int $methodId): bool
    {
        return preg_match('#^sowing-methods/'.$methodId.'/[a-zA-Z0-9-]+\.(?:jpg|jpeg|png|webp)$#D', $path) === 1;
    }

    /** @param list<string> $paths */
    private function deleteImageFiles(array $paths, int $methodId): void
    {
        foreach ($paths as $path) {
            if (! $this->safeImagePath($path, $methodId)) {
                Log::warning('Skipped invalid sowing method image cleanup path.', ['sowing_method_id' => $methodId]);

                continue;
            }
            try {
                if (! Storage::disk(config('filesystems.sowing_method_images_disk'))->delete($path)) {
                    Log::warning('Sowing method image cleanup failed.', ['sowing_method_id' => $methodId]);
                }
            } catch (Throwable $exception) {
                Log::warning('Sowing method image cleanup failed.', ['sowing_method_id' => $methodId, 'exception_type' => $exception::class]);
            }
        }
    }
}
