<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A farmer's dated copy of one crop activity template. Status is stored; the time state
 * (upcoming, due, late, done, passed) is derived from the dates and status and never stored.
 */
class FarmCropActivity extends Model
{
    public const STATUSES = ['pending', 'in_progress', 'completed'];

    protected $fillable = [
        'farm_crop_cycle_id', 'crop_activity_template_id', 'planned_start_date', 'planned_end_date',
        'start_date', 'end_date', 'status', 'started_at', 'completed_at',
    ];

    protected $attributes = ['status' => 'pending'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['farm_crop_cycle_id' => 'integer', 'crop_activity_template_id' => 'integer',
            'planned_start_date' => 'immutable_date:Y-m-d', 'planned_end_date' => 'immutable_date:Y-m-d',
            'start_date' => 'immutable_date:Y-m-d', 'end_date' => 'immutable_date:Y-m-d',
            'started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(FarmCropCycle::class, 'farm_crop_cycle_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(CropActivityTemplate::class, 'crop_activity_template_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(FarmCropActivityNote::class);
    }

    /** Information activities have no status: they are never late and never counted as tasks. */
    public function tracksStatus(): bool
    {
        return (bool) $this->template->allow_status_update;
    }

    public function isRescheduled(): bool
    {
        return ! $this->start_date->equalTo($this->planned_start_date) || ! $this->end_date->equalTo($this->planned_end_date);
    }

    /** @param  string  $today  Y-m-d in the timeline timezone */
    public function timeState(string $today): string
    {
        $start = $this->start_date->toDateString();
        $end = $this->end_date->toDateString();
        if (! $this->tracksStatus()) {
            return $today < $start ? 'upcoming' : ($today > $end ? 'passed' : 'due');
        }
        if ($this->status === 'completed') {
            return 'done';
        }

        return $today > $end ? 'late' : ($today >= $start ? 'due' : 'upcoming');
    }

    /** The first record of any kind starts a pending activity. Deleting records never reverts it. */
    public function markRecordAdded(): void
    {
        if ($this->tracksStatus() && $this->status === 'pending') {
            $this->forceFill(['status' => 'in_progress', 'started_at' => now()])->save();
        }
    }
}
