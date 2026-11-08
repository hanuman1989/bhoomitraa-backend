<?php

namespace App\Models;

use Database\Factories\SeedTranslationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeedTranslation extends Model
{
    /** @use HasFactory<SeedTranslationFactory> */
    use HasFactory;

    protected $fillable = ['locale', 'name', 'description', 'planting_instructions'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['seed_id' => 'integer'];
    }

    public function seed(): BelongsTo
    {
        return $this->belongsTo(Seed::class);
    }
}
