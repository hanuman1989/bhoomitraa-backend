<?php

namespace App\Models;

use Database\Factories\FertilizerNutrientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FertilizerNutrient extends Model
{
    /** @use HasFactory<FertilizerNutrientFactory> */
    use HasFactory;

    public const CODES = ['N', 'P2O5', 'K2O', 'S', 'Zn', 'Fe', 'Mn', 'Cu', 'B', 'Ca', 'Mg', 'OC'];

    public const CONTENT_UNITS = ['percent_ww', 'percent_wv', 'g_per_l'];

    protected $fillable = ['nutrient_code', 'content_value', 'content_unit_code'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['fertilizer_id' => 'integer', 'content_value' => 'decimal:4'];
    }

    public function fertilizer(): BelongsTo
    {
        return $this->belongsTo(Fertilizer::class);
    }

    public function hasExternalReferences(): bool
    {
        foreach (Schema::getTables() as $table) {
            foreach (Schema::getForeignKeys($table['name']) as $foreignKey) {
                if (($foreignKey['foreign_table'] ?? null) === 'fertilizer_nutrients' && ($foreignKey['foreign_columns'] ?? []) === ['id']
                    && count($foreignKey['columns'] ?? []) === 1 && DB::table($table['name'])->where($foreignKey['columns'][0], $this->id)->exists()) {
                    return true;
                }
            }
        }

        return false;
    }
}
