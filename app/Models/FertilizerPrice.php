<?php

namespace App\Models;

use Database\Factories\FertilizerPriceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class FertilizerPrice extends Model
{
    /** @use HasFactory<FertilizerPriceFactory> */
    use HasFactory;

    protected $fillable = ['fertilizer_id', 'seller_name', 'price_type', 'package_quantity', 'package_unit_code', 'currency_code', 'package_price',
        'region_scope', 'effective_from', 'effective_to', 'source_id', 'last_verified_at', 'is_active'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['fertilizer_id' => 'integer', 'source_id' => 'integer', 'package_quantity' => 'decimal:4', 'package_price' => 'decimal:4', 'is_active' => 'boolean',
            'effective_from' => 'immutable_date:Y-m-d', 'effective_to' => 'immutable_date:Y-m-d', 'last_verified_at' => 'immutable_datetime'];
    }

    public function fertilizer(): BelongsTo
    {
        return $this->belongsTo(Fertilizer::class);
    }

    public function knowledgeSource(): BelongsTo
    {
        return $this->belongsTo(KnowledgeSource::class, 'source_id');
    }

    public function regions(): BelongsToMany
    {
        return $this->belongsToMany(Region::class, 'fertilizer_price_regions');
    }
}
