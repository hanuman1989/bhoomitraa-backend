<?php

namespace App\Models;

use Database\Factories\FertilizerTranslationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FertilizerTranslation extends Model
{
    /** @use HasFactory<FertilizerTranslationFactory> */
    use HasFactory;

    protected $fillable = ['locale', 'name', 'description', 'usage_instructions'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['fertilizer_id' => 'integer'];
    }

    public function fertilizer(): BelongsTo
    {
        return $this->belongsTo(Fertilizer::class);
    }
}
