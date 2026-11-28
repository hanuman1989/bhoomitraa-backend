<?php

namespace App\Services;

use App\Http\Requests\Admin\SaveCropFieldLayoutTranslationsRequest;
use App\Http\Requests\Admin\StoreCropFieldLayoutRequest;
use App\Models\Crop;
use App\Models\CropCategory;
use App\Models\CropFieldLayout;
use App\Models\CropFieldLayoutTranslation;
use App\Models\CropVariety;
use App\Models\SowingMethod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CropFieldLayoutManager
{
    /** @param array<string, mixed> $input */
    public function saveBasicDetails(array $input, ?CropFieldLayout $existing = null): CropFieldLayout
    {
        return DB::transaction(function () use ($input, $existing): CropFieldLayout {
            $layout = $existing === null ? new CropFieldLayout : $this->locked($existing);
            $this->checkRevision($layout, $input);
            $values = $layout->exists ? $this->snapshot($layout) : [
                'crop_scope' => 'specific', 'crop_ids' => [], 'crop_variety_id' => null, 'sowing_method_id' => null,
                'farming_type' => null, 'growing_system' => null, 'sort_order' => 0, 'is_active' => false,
                'configuration' => [], 'configuration_version' => CropFieldLayoutOptions::VERSION, 'reference_note' => null, 'translations' => [],
            ];
            $oldType = $values['layout_type'] ?? null;
            $values = array_replace($values, Arr::only($input, [...CropFieldLayout::BASIC_FIELDS, 'crop_ids', 'is_active']));
            $values['is_active'] = (bool) $values['is_active'];
            $values['crop_ids'] = array_map('intval', $values['crop_ids']);
            if ($values['crop_scope'] === 'all') {
                $values['crop_ids'] = [];
                $values['crop_variety_id'] = null;
            }
            $this->ensureValid($this->basicErrors($values, lock: true));
            $draft = $this->draft($layout);
            if ($oldType !== null && $oldType !== $values['layout_type']) {
                $values['configuration'] = [];
                $values['configuration_version'] = CropFieldLayoutOptions::VERSION;
                $draft['completed_steps'] = array_values(array_diff($draft['completed_steps'], [2]));
            }
            $draft['completed_steps'] = array_values(array_unique([...$draft['completed_steps'], 1]));
            $this->persist($layout, $values, $draft);

            return $layout;
        }, attempts: 3);
    }

    /** @param array<string, mixed> $input */
    public function saveConfiguration(CropFieldLayout $existing, array $input): CropFieldLayout
    {
        return DB::transaction(function () use ($existing, $input): CropFieldLayout {
            $layout = $this->locked($existing);
            $this->checkRevision($layout, $input);
            $draft = $this->draft($layout);
            abort_unless(in_array(1, $draft['completed_steps'], true), 409, 'Save basic details first.');
            $values = $this->snapshot($layout);
            $this->ensureValid(CropFieldLayoutOptions::configurationErrors($values['layout_type'], $input['configuration']));
            $values['configuration'] = CropFieldLayoutOptions::normalize($values['layout_type'], $input['configuration']);
            $values['configuration_version'] = CropFieldLayoutOptions::VERSION;
            if (array_key_exists('reference_note', $input)) {
                $values['reference_note'] = $input['reference_note'];
            }
            $draft['completed_steps'] = array_values(array_unique([...$draft['completed_steps'], 2]));
            $this->persist($layout, $values, $draft);

            return $layout;
        }, attempts: 3);
    }

    /** @param array<string, mixed> $input */
    public function saveTranslations(CropFieldLayout $existing, array $input): CropFieldLayout
    {
        return DB::transaction(function () use ($existing, $input): CropFieldLayout {
            $layout = $this->locked($existing);
            $this->checkRevision($layout, $input);
            $draft = $this->draft($layout);
            abort_unless(in_array(2, $draft['completed_steps'], true), 409, 'Save layout configuration first.');
            $values = $this->snapshot($layout);
            foreach ($input['translations'] as $locale => $fields) {
                $merged = array_replace($values['translations'][$locale] ?? [], $fields);
                if (blank($merged['title'] ?? null)) {
                    throw ValidationException::withMessages(['translations.'.$locale.'.title' => ['A title is required when adding a translation.']]);
                }
                $values['translations'][$locale] = $merged;
            }
            $this->ensureValid(Validator::make(['translations' => $values['translations']],
                SaveCropFieldLayoutTranslationsRequest::fieldRules(complete: true))->errors()->messages());
            $draft['completed_steps'] = array_values(array_unique([...$draft['completed_steps'], 3]));
            $this->persist($layout, $values, $draft);

            return $layout;
        }, attempts: 3);
    }

    /** @param array<string, mixed> $input */
    public function finalize(CropFieldLayout $existing, array $input): CropFieldLayout
    {
        return DB::transaction(function () use ($existing, $input): CropFieldLayout {
            $layout = $this->locked($existing);
            if ($layout->wizard_draft !== null) {
                $this->checkRevision($layout, $input);
            }
            $values = $this->snapshot($layout);
            $this->ensureValid($this->completionErrors($layout, $values, lock: true));
            if ($layout->wizard_draft !== null) {
                $this->apply($layout, $values);
                $layout->wizard_draft = null;
                $layout->save();
            }

            return $layout;
        }, attempts: 3);
    }

    /** @return array<string, mixed> */
    public function snapshot(CropFieldLayout $layout): array
    {
        if (isset($layout->wizard_draft['data'])) {
            return $layout->wizard_draft['data'];
        }
        $layout->loadMissing(['crops:id', 'translations']);

        return [...$layout->only([...CropFieldLayout::BASIC_FIELDS, 'configuration', 'configuration_version', 'reference_note']),
            'configuration' => $layout->configuration ?? [],
            'crop_ids' => $layout->crops->modelKeys(),
            'is_active' => $layout->wizard_draft['requested_is_active'] ?? $layout->is_active,
            'translations' => $layout->translations->mapWithKeys(fn (CropFieldLayoutTranslation $row): array => [$row->locale => $row->only(CropFieldLayout::TRANSLATED_FIELDS)])->all()];
    }

    /** @param array<string, mixed>|null $values @return array<string, list<string>> */
    public function completionErrors(CropFieldLayout $layout, ?array $values = null, bool $lock = false): array
    {
        $values ??= $this->snapshot($layout);
        $errors = $this->basicErrors($values, $lock);
        $errors = [...$errors, ...CropFieldLayoutOptions::configurationErrors($values['layout_type'], $values['configuration']),
            ...Validator::make(['translations' => $values['translations']],
                SaveCropFieldLayoutTranslationsRequest::fieldRules(complete: true))->errors()->messages()];
        if (array_diff([1, 2, 3], $layout->wizard_draft['completed_steps'] ?? [1, 2, 3]) !== []) {
            $errors['completed_step'] = ['Save all three wizard steps before finalizing.'];
        }
        if ($values['configuration_version'] !== CropFieldLayoutOptions::VERSION) {
            $errors['configuration_version'] = ['Save configuration using the current supported version.'];
        }

        return $errors;
    }

    /** @return array<string, mixed> */
    public function wizard(CropFieldLayout $layout, bool $readiness = true): array
    {
        $draft = $layout->wizard_draft;
        $steps = $draft['completed_steps'] ?? [1, 2, 3, 4];
        $completed = 0;
        foreach ([1, 2, 3, 4] as $step) {
            if (! in_array($step, $steps, true)) {
                break;
            }
            $completed = $step;
        }
        $state = ['completed_step' => $completed, 'completed_steps' => $steps,
            'is_finalized' => $draft === null, 'has_pending_edit' => $draft['is_edit'] ?? false,
            'requested_is_active' => $draft['requested_is_active'] ?? $layout->is_active,
            'revision' => $draft['revision'] ?? 0];
        if ($readiness) {
            $errors = $this->completionErrors($layout);
            $state['can_finalize'] = $errors === [];
            $state['readiness_errors'] = (object) $errors;
        }

        return $state;
    }

    public function delete(CropFieldLayout $existing): bool
    {
        try {
            return DB::transaction(function () use ($existing): bool {
                $layout = $this->locked($existing);
                foreach (Schema::getTables() as $table) {
                    if (in_array($table['name'], ['crop_field_layout_translations', 'crop_field_layout_crops'], true)) {
                        continue;
                    }
                    foreach (Schema::getForeignKeys($table['name']) as $key) {
                        if (($key['foreign_table'] ?? null) === 'crop_field_layouts' && ($key['foreign_columns'] ?? []) === ['id']
                            && count($key['columns'] ?? []) === 1 && DB::table($table['name'])->where($key['columns'][0], $layout->id)->exists()) {
                            return false;
                        }
                    }
                }
                $layout->delete();

                return true;
            }, attempts: 3);
        } catch (QueryException $exception) {
            if (in_array((int) ($exception->errorInfo[1] ?? 0), [1451, 787], true)) {
                return false;
            }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $values @return array<string, list<string>> */
    private function basicErrors(array $values, bool $lock = false): array
    {
        $errors = Validator::make($values, StoreCropFieldLayoutRequest::fieldRules())->errors()->messages();
        if ($errors !== []) {
            return $errors;
        }
        $ids = $values['crop_ids'];
        if ($values['crop_scope'] === 'specific') {
            if ($ids === []) {
                $errors['crop_ids'] = ['Select at least one eligible crop.'];
            } else {
                if ($lock) {
                    $crops = Crop::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get(['id', 'category_id']);
                    CropCategory::query()->whereKey($crops->pluck('category_id')->unique())->orderBy('id')->lockForUpdate()->get(['id']);
                }
                if (Crop::query()->published()->whereKey($ids)->count() !== count($ids)) {
                    $errors['crop_ids'] = ['Select active crops with active categories and required translations.'];
                }
            }
        } elseif ($ids !== [] || $values['crop_variety_id'] !== null) {
            $errors['crop_ids'] = ['All-crops layouts cannot restrict crops or varieties.'];
        }
        if ($values['crop_variety_id'] !== null) {
            if ($values['crop_scope'] !== 'specific' || count($ids) !== 1 ||
                ! CropVariety::query()->whereKey($values['crop_variety_id'])->where('crop_id', $ids[0])
                    ->where('is_active', true)->when($lock, fn (Builder $query): Builder => $query->lockForUpdate())->exists()) {
                $errors['crop_variety_id'] = ['Select an active variety belonging to the single selected crop.'];
            }
        }
        if ($values['sowing_method_id'] !== null) {
            $method = SowingMethod::query()->when($lock, fn (Builder $query): Builder => $query->lockForUpdate())->find($values['sowing_method_id']);
            if ($method === null || ! SowingMethod::query()->published()->whereKey($method->id)->exists()) {
                $errors['sowing_method_id'] = ['Select an eligible active sowing method.'];
            } elseif ($method->crop_scope !== 'all' && ($values['crop_scope'] === 'all' ||
                $method->crops()->whereIn('crops.id', $ids)->count() !== count($ids))) {
                $errors['sowing_method_id'] = ['The sowing method must apply to every selected crop.'];
            }
        }

        return $errors;
    }

    private function locked(CropFieldLayout $layout): CropFieldLayout
    {
        return CropFieldLayout::query()->lockForUpdate()->findOrFail($layout->id);
    }

    /** @param array<string, mixed> $input */
    private function checkRevision(CropFieldLayout $layout, array $input): void
    {
        if (array_key_exists('draft_revision', $input)) {
            abort_if((int) $input['draft_revision'] !== ($layout->wizard_draft['revision'] ?? 0), 409, 'The draft has changed. Reload it before saving.');
        }
    }

    /** @return array<string, mixed> */
    private function draft(CropFieldLayout $layout): array
    {
        return $layout->wizard_draft ?? ['is_edit' => $layout->exists, 'completed_steps' => $layout->exists ? [1, 2, 3] : [], 'revision' => 0];
    }

    /** @param array<string, mixed> $values @param array<string, mixed> $draft */
    private function persist(CropFieldLayout $layout, array $values, array $draft): void
    {
        $draft['requested_is_active'] = $values['is_active'];
        $draft['revision']++;
        sort($draft['completed_steps']);
        if ($draft['is_edit']) {
            $draft['data'] = $values;
        } else {
            $this->apply($layout, [...$values, 'is_active' => false]);
        }
        $layout->wizard_draft = $draft;
        $layout->save();
    }

    /** @param array<string, mixed> $values */
    private function apply(CropFieldLayout $layout, array $values): void
    {
        $layout->fill(Arr::only($values, $layout->getFillable()));
        $layout->configuration = (object) $values['configuration'];
        $layout->save();
        $layout->crops()->sync($values['crop_ids']);
        foreach ($values['translations'] as $locale => $fields) {
            $layout->translations()->updateOrCreate(['locale' => $locale], Arr::only($fields, CropFieldLayout::TRANSLATED_FIELDS));
        }
        $layout->unsetRelations();
    }

    /** @param array<string, list<string>> $errors */
    private function ensureValid(array $errors): void
    {
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
