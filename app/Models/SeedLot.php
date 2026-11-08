<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\SeedLotFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SeedLot extends Model
{
    /** @use HasFactory<SeedLotFactory> */
    use HasFactory;

    public const VALIDITY_STATUSES = ['all', 'valid', 'expiring_soon', 'expired', 'no_expiry'];

    public const REVIEW_FIELDS = ['seed_id', 'lot_number', 'seed_class', 'production_date', 'test_date', 'valid_until',
        'germination_percentage', 'physical_purity_percentage', 'genetic_purity_percentage', 'seed_treatment_status',
        'certification_number', 'certification_body', 'notes'];

    protected $fillable = [...self::REVIEW_FIELDS, 'is_active'];

    protected $attributes = ['is_active' => true, 'verification_status' => 'pending'];

    protected $hidden = ['report_file'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['seed_id' => 'integer', 'verified_by' => 'integer', 'is_active' => 'boolean', 'verified_at' => 'immutable_datetime',
            'production_date' => 'immutable_date:Y-m-d', 'test_date' => 'immutable_date:Y-m-d', 'valid_until' => 'immutable_date:Y-m-d',
            'germination_percentage' => 'decimal:2', 'physical_purity_percentage' => 'decimal:2', 'genetic_purity_percentage' => 'decimal:2'];
    }

    public function seed(): BelongsTo
    {
        return $this->belongsTo(Seed::class)->withTrashed();
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'verified_by');
    }

    /** Lot activity and verification are independent; parent catalogue eligibility remains authoritative. */
    public function scopeSelectable(Builder $query): void
    {
        $query->where('seed_lots.is_active', true)->whereHas('seed', fn (Builder $seed): Builder => $seed->withoutTrashed()->published());
    }

    public function validityStatus(?CarbonImmutable $today = null): string
    {
        $today ??= CarbonImmutable::today(config('app.timezone'));
        if ($this->valid_until === null) {
            return 'no_expiry';
        }
        $date = $this->valid_until->toDateString();
        if ($date < $today->toDateString()) {
            return 'expired';
        }

        return $date <= $today->addDays((int) config('seed_lots.expiring_soon_days'))->toDateString() ? 'expiring_soon' : 'valid';
    }

    public function scopeWhereValidity(Builder $query, string $status, CarbonImmutable $today): void
    {
        $start = $today->toDateString();
        $afterWindow = $today->addDays((int) config('seed_lots.expiring_soon_days') + 1)->toDateString();
        match ($status) {
            'valid' => $query->where('valid_until', '>=', $afterWindow),
            'expiring_soon' => $query->where('valid_until', '>=', $start)->where('valid_until', '<', $afterWindow),
            'expired' => $query->where('valid_until', '<', $start),
            'no_expiry' => $query->whereNull('valid_until'),
            default => $query,
        };
    }

    /** @return array<string, list<string>> */
    public function validationErrors(bool $lock = false): array
    {
        $errors = [];
        $seed = Seed::withTrashed()->whereKey($this->seed_id)->when($lock, fn (Builder $query): Builder => $query->lockForUpdate())->first();
        if ($seed === null || ((! $this->exists || $this->isDirty('seed_id')) && ! Seed::query()->published()->whereKey($this->seed_id)->exists())) {
            $errors['seed_id'] = ['Select an available completed and published seed product.'];
        }
        if ($this->exists && $this->isDirty('seed_id') && $this->hasExternalReferences()) {
            $errors['seed_id'] = ['The parent seed cannot be changed while this lot is referenced by historical records.'];
        }
        if ($this->exists && $this->isDirty('seed_id') && self::query()->where('seed_id', $this->seed_id)
            ->where('lot_number', $this->lot_number)->whereKeyNot($this->id)->exists()) {
            $errors['lot_number'] = ['The lot number has already been taken for the selected seed.'];
        }
        if ($this->production_date !== null && $this->test_date !== null && $this->production_date->gt($this->test_date)) {
            $errors['test_date'] = ['The test date must be on or after the production date.'];
        }
        if ($this->production_date !== null && $this->valid_until !== null && $this->production_date->gt($this->valid_until)) {
            $errors['valid_until'] = ['The validity date must be on or after the production date.'];
        }

        return $errors;
    }

    /** Review decisions follow Knowledge Sources: retain reviewer metadata for rejection/outdating, clear it on pending. */
    public function setVerification(string $status, int $adminId, bool $renew = false): void
    {
        if ($status === $this->verification_status && ! $renew) {
            return;
        }
        $this->verification_status = $status;
        $this->verified_by = $status === 'pending' ? null : $adminId;
        $this->verified_at = $status === 'pending' ? null : now('UTC');
    }

    public function resetVerification(): void
    {
        $this->verification_status = 'pending';
        $this->verified_by = null;
        $this->verified_at = null;
    }

    /** Protect actual foreign-key references, including cascading or nullable historical links. */
    public function hasExternalReferences(): bool
    {
        foreach (Schema::getTables() as $table) {
            foreach (Schema::getForeignKeys($table['name']) as $foreignKey) {
                if (($foreignKey['foreign_table'] ?? null) === 'seed_lots' && ($foreignKey['foreign_columns'] ?? []) === ['id']
                    && count($foreignKey['columns'] ?? []) === 1 && DB::table($table['name'])->where($foreignKey['columns'][0], $this->id)->exists()) {
                    return true;
                }
            }
        }

        return false;
    }
}
