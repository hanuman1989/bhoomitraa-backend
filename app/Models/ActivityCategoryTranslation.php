<?php

namespace App\Models;

use Database\Factories\ActivityCategoryTranslationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityCategoryTranslation extends Model
{
    /** @use HasFactory<ActivityCategoryTranslationFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['locale', 'name', 'description'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public function activityCategory(): BelongsTo
    {
        return $this->belongsTo(ActivityCategory::class);
    }
}
