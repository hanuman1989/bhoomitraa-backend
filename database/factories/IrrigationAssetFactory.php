<?php

namespace Database\Factories;

use App\Enums\IrrigationAssetStatus;
use App\Enums\IrrigationAssetType;
use App\Enums\IrrigationOwnershipType;
use App\Enums\IrrigationPipelineType;
use App\Enums\IrrigationSourceType;
use App\Enums\IrrigationWaterAvailability;
use App\Models\IrrigationAsset;
use App\Models\User;
use App\Services\Irrigation\IrrigationGeometryService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Unmapped borewell by default. There is deliberately no seeder: production never gets demo data.
 *
 * @extends Factory<IrrigationAsset>
 */
class IrrigationAssetFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            ...$this->sourceAttributes(),
            'ownership_type' => IrrigationOwnershipType::Owned,
            'status' => IrrigationAssetStatus::Operational,
            'notes' => null,
        ];
    }

    public function source(): static
    {
        return $this->state(fn (): array => $this->sourceAttributes());
    }

    public function pipeline(): static
    {
        return $this->state(fn (): array => [
            'asset_type' => IrrigationAssetType::Pipeline,
            'source_type' => null,
            'pipeline_type' => IrrigationPipelineType::Underground,
            'length_meters' => null,
            'diameter_mm' => '90.00',
            'depth_meters' => null,
            'water_availability' => null,
        ]);
    }

    /** Stores a point near Jaipur for a source, or a two-point route and its calculated length for a pipeline. */
    public function mapped(): static
    {
        return $this->afterCreating(function (IrrigationAsset $asset): void {
            if ($asset->asset_type === IrrigationAssetType::Source) {
                $asset->persistGeometry(['type' => 'Point', 'coordinates' => [75.8001, 26.9101]]);

                return;
            }
            $route = ['type' => 'LineString', 'coordinates' => [[75.8001, 26.9101], [75.8011, 26.9111]]];
            $asset->forceFill(['length_meters' => (new IrrigationGeometryService)->lengthMeters($route['coordinates'])])->save();
            $asset->persistGeometry($route);
        });
    }

    /** No geometry (the default); explicit for readable tests. */
    public function unmapped(): static
    {
        return $this->state(fn (): array => []);
    }

    /** @return array<string, mixed> */
    private function sourceAttributes(): array
    {
        return [
            'asset_type' => IrrigationAssetType::Source,
            'source_type' => IrrigationSourceType::Borewell,
            'pipeline_type' => null,
            'length_meters' => null,
            'diameter_mm' => null,
            'depth_meters' => '120.00',
            'water_availability' => IrrigationWaterAvailability::Good,
        ];
    }
}
