<?php

namespace App\Models;

use Database\Factories\FarmerProfileFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FarmerProfile extends Model
{
    /** @use HasFactory<FarmerProfileFactory> */
    use HasFactory;

    protected $fillable = ['profile_image', 'state_id', 'district_id', 'village', 'address', 'pincode', 'preferred_language', 'preferred_units'];

    protected $attributes = ['preferred_language' => 'hi'];

    protected function casts(): array
    {
        return ['user_id' => 'integer', 'state_id' => 'integer', 'district_id' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'state_id');
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'district_id');
    }

    public function scopeWithLocationNames(Builder $query): void
    {
        $query->with([
            'state' => fn (BelongsTo $state): BelongsTo => $state->withLocalizedName(),
            'district' => fn (BelongsTo $district): BelongsTo => $district->withLocalizedName(),
        ]);
    }

    /** @return array<string, list<string>> */
    public function locationErrors(bool $lock = false): array
    {
        $regions = Region::query()->whereIn('id', [$this->state_id, $this->district_id])->orderBy('id')
            ->when($lock, fn (Builder $query): Builder => $query->lockForUpdate())
            ->get(['id', 'parent_id', 'region_type', 'is_active'])->keyBy('id');
        $errors = [];
        foreach (['state_id' => 'state', 'district_id' => 'district'] as $field => $type) {
            $region = $regions->get($this->{$field});
            if ($region === null || $region->region_type !== $type) {
                $errors[$field] = ['Select an existing '.$type.'.'];
            } elseif (! $region->is_active && (! $this->exists || $this->isDirty($field))) {
                $errors[$field] = ['Inactive '.$type.'s cannot be newly selected.'];
            }
        }
        $district = $regions->get($this->district_id);
        if (! isset($errors['district_id']) && $district->parent_id !== $this->state_id) {
            $errors['district_id'] = ['The district must belong to the selected state.'];
        }

        return $errors;
    }
}
