<?php

use App\Helpers\AreaHelper;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $columns = array_values(array_intersect(['area_sqm', 'actual_area_sqm'], Schema::getColumnListing('farms')));
        $selection = ['id', 'area', 'area_unit', 'actual_area', 'actual_area_unit', ...$columns];

        /** Preflight before DDL: an unresolved or conflicting stored measurement must never be dropped. */
        DB::table('farms')->select($selection)->orderBy('id')->chunkById(200, function (Collection $farms): void {
            foreach ($farms as $farm) {
                $this->preservedActualArea($farm);
                if (isset($farm->area_sqm)) {
                    $calculated = AreaHelper::toSquareMeters((string) $farm->area, $farm->area_unit);
                    if ($calculated === null || ! BigDecimal::of($calculated)->toScale(6, RoundingMode::HALF_UP)
                        ->isEqualTo(BigDecimal::of((string) $farm->area_sqm)->toScale(6, RoundingMode::HALF_UP))) {
                        throw new RuntimeException('Resolve the stored recorded square-metre measurement for farm '.$farm->id.' before migrating.');
                    }
                }
            }
        });

        Schema::table('farms', function (Blueprint $table): void {
            $table->decimal('actual_area', 18, 8)->nullable()->change();
        });

        DB::transaction(function () use ($selection): void {
            DB::table('farms')->select($selection)->orderBy('id')->chunkById(200, function (Collection $farms): void {
                foreach ($farms as $farm) {
                    $values = $this->preservedActualArea($farm);
                    if ($values !== null) {
                        DB::table('farms')->where('id', $farm->id)->update($values);
                    }
                }
            });
        });

        $this->dropAreaConstraints();

        if ($columns !== []) {
            Schema::table('farms', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }

        $this->addAreaConstraints();

        Schema::table('farmer_profiles', function (Blueprint $table): void {
            $table->string('preferred_units', 20)->default(config('area_units.default_unit'))->change();
        });
    }

    /**
     * Only an empty farm schema can be rolled back without losing historical or new measurement precision.
     */
    public function down(): void
    {
        if (DB::table('farms')->exists()) {
            throw new RuntimeException('Cannot roll back populated farm measurements without losing precision. Restore the pre-migration backup or deploy a forward fix.');
        }

        $this->dropAreaConstraints();
        Schema::table('farms', function (Blueprint $table): void {
            $table->decimal('actual_area', 12, 3)->nullable()->change();
            $table->decimal('actual_area_sqm', 18, 6)->nullable();
        });
        $this->addAreaConstraints(legacy: true);
        Schema::table('farmer_profiles', function (Blueprint $table): void {
            $table->string('preferred_units', 20)->default('bigha')->change();
        });
    }

    private function dropAreaConstraints(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            foreach (['actual_area_sqm', 'boundary'] as $name) {
                foreach (['insert', 'update'] as $operation) {
                    DB::unprepared('DROP TRIGGER IF EXISTS chk_farms_'.$name.'_'.$operation);
                }
            }
        } else {
            $constraints = DB::table('information_schema.TABLE_CONSTRAINTS')
                ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())->where('TABLE_NAME', 'farms')
                ->whereIn('CONSTRAINT_NAME', ['chk_farms_actual_area_sqm', 'chk_farms_boundary'])->pluck('CONSTRAINT_NAME');
            foreach ($constraints as $constraint) {
                DB::statement('ALTER TABLE farms DROP '.(DB::connection()->isMaria() ? 'CONSTRAINT ' : 'CHECK ').$constraint);
            }
        }
    }

    private function addAreaConstraints(bool $legacy = false): void
    {
        $emptySquareMetres = $legacy ? ' AND actual_area_sqm IS NULL' : '';
        $presentSquareMetres = $legacy ? ' AND actual_area_sqm IS NOT NULL' : '';
        $checks = ['boundary' => '(boundary IS NULL AND boundary_source IS NULL AND actual_area IS NULL AND actual_area_unit IS NULL AND gps_accuracy_m IS NULL'.$emptySquareMetres.") OR (boundary IS NOT NULL AND boundary_source IS NOT NULL AND boundary_source IN ('manual', 'gps') AND actual_area IS NOT NULL AND actual_area_unit IS NOT NULL".$presentSquareMetres.')'];
        if ($legacy) {
            $checks['actual_area_sqm'] = 'actual_area_sqm IS NULL OR actual_area_sqm > 0';
        }
        foreach ($checks as $name => $expression) {
            if (DB::getDriverName() === 'sqlite') {
                $expression = preg_replace('/\b(boundary|boundary_source|actual_area|actual_area_unit|actual_area_sqm|gps_accuracy_m)\b/', 'NEW.$1', $expression);
                foreach (['INSERT', 'UPDATE'] as $operation) {
                    DB::unprepared('CREATE TRIGGER chk_farms_'.$name.'_'.strtolower($operation).' BEFORE '.$operation.' ON farms FOR EACH ROW WHEN NOT ('.$expression.") BEGIN SELECT RAISE(ABORT, 'Invalid farm area, boundary or coordinates.'); END");
                }
            } else {
                DB::statement('ALTER TABLE farms ADD CONSTRAINT chk_farms_'.$name.' CHECK ('.$expression.')');
            }
        }
    }

    /** @return array{actual_area: string, actual_area_unit: string}|null */
    private function preservedActualArea(object $farm): ?array
    {
        if (! isset($farm->actual_area_sqm)) {
            return null;
        }

        $squareMetres = AreaHelper::toSquareMeters((string) $farm->actual_area_sqm, 'sqm');
        if ($squareMetres === null || BigDecimal::of($squareMetres)->isLessThanOrEqualTo(0)) {
            throw new RuntimeException('Resolve the actual square-metre measurement for farm '.$farm->id.' before migrating.');
        }

        $unit = AreaHelper::isSupported($farm->actual_area_unit) ? $farm->actual_area_unit : 'sqm';
        $converted = BigDecimal::of(AreaHelper::fromSquareMeters($squareMetres, $unit))->toScale(8, RoundingMode::HALF_UP);
        $reconstructed = BigDecimal::of(AreaHelper::toSquareMeters((string) $converted, $unit))->toScale(6, RoundingMode::HALF_UP);
        if (! $reconstructed->isEqualTo($squareMetres)) {
            $unit = 'sqm';
            $converted = BigDecimal::of($squareMetres)->toScale(8, RoundingMode::HALF_UP);
        }
        if ($converted->isLessThanOrEqualTo(0) || $converted->isGreaterThan('9999999999.99999999')) {
            throw new RuntimeException('The actual measurement for farm '.$farm->id.' cannot be preserved at the target precision. Keep the legacy columns and resolve it before migrating.');
        }

        return ['actual_area' => (string) $converted, 'actual_area_unit' => $unit];
    }
};
