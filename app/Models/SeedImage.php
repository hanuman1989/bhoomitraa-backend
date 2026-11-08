<?php

namespace App\Models;

use Database\Factories\SeedImageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeedImage extends Model
{
    /** @use HasFactory<SeedImageFactory> */
    use HasFactory;

    protected $fillable = ['image_path', 'alt_text', 'sort_order'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['seed_id' => 'integer', 'sort_order' => 'integer'];
    }

    public function seed(): BelongsTo
    {
        return $this->belongsTo(Seed::class);
    }
}
