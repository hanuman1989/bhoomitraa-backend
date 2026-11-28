<?php

namespace App\Models;

use Database\Factories\SowingMethodImageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SowingMethodImage extends Model
{
    /** @use HasFactory<SowingMethodImageFactory> */
    use HasFactory;

    protected $fillable = ['image', 'sort_order'];

    protected $hidden = ['image'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['sowing_method_id' => 'integer', 'sort_order' => 'integer'];
    }

    public function sowingMethod(): BelongsTo
    {
        return $this->belongsTo(SowingMethod::class);
    }
}
