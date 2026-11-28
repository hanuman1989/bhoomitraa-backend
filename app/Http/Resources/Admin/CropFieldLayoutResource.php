<?php

namespace App\Http\Resources\Admin;

use App\Models\Crop;
use App\Models\CropFieldLayoutTranslation;
use App\Services\CropFieldLayoutOptions;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CropFieldLayoutResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $locales = [app()->getLocale(), config('languages.default')];
        $variety = $this->resource->relationLoaded('cropVariety') ? $this->cropVariety : null;
        $method = $this->resource->relationLoaded('sowingMethod') ? $this->sowingMethod : null;

        return [
            'id' => $this->id,
            ...$this->resource->only($this->resource->getFillable()),
            'configuration' => (object) ($this->configuration ?? []),
            'title' => $this->resource->translation()?->title ?? 'Draft layout #'.$this->id,
            'crops' => $this->whenLoaded('crops', fn (): array => $this->crops->map(fn (Crop $crop): array => [
                'id' => $crop->id, 'name' => $crop->translatedName(),
            ])->all()),
            'crop_variety' => $variety === null ? null : ['id' => $variety->id, 'crop_id' => $variety->crop_id,
                'name' => $variety->translations->firstWhere('locale', $locales[0])?->name
                    ?? $variety->translations->firstWhere('locale', $locales[1])?->name],
            'sowing_method' => $method === null ? null : ['id' => $method->id, 'title' => $method->translation()?->title, 'crop_scope' => $method->crop_scope],
            'layout_type_label' => CropFieldLayoutOptions::definition($this->layout_type)['label'] ?? null,
            'farming_type_label' => CropFieldLayoutOptions::FARMING_TYPES[$this->farming_type] ?? null,
            'growing_system_label' => CropFieldLayoutOptions::GROWING_SYSTEMS[$this->growing_system] ?? null,
            'configuration_definition' => $this->when($this->resource->getAttribute('detail_view') === true,
                fn (): array => CropFieldLayoutOptions::definition($this->layout_type)),
            'translations' => $this->when($this->resource->getAttribute('detail_view') === true,
                fn (): object => (object) $this->translations->mapWithKeys(fn (CropFieldLayoutTranslation $row): array => [
                    $row->locale => ['locale' => $row->locale, 'language_name' => config('languages.supported.'.$row->locale, $row->locale),
                        ...$row->only(['title', 'description', 'important_considerations'])],
                ])->all()),
            'wizard' => $this->wizard_state,
            'draft' => $this->when($this->resource->getAttribute('detail_view') === true, fn (): mixed => $this->draft_view),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
