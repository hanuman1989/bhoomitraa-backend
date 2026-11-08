<?php

namespace App\Models;

use Database\Factories\FertilizerImageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FertilizerImage extends Model
{
    /** @use HasFactory<FertilizerImageFactory> */
    use HasFactory;

    protected $fillable = ['image_path', 'alt_text', 'sort_order'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['fertilizer_id' => 'integer', 'sort_order' => 'integer'];
    }

    public function fertilizer(): BelongsTo
    {
        return $this->belongsTo(Fertilizer::class);
    }
}
