<?php

namespace App\Models;

use Database\Factories\KnowledgeSourceTranslationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeSourceTranslation extends Model
{
    /** @use HasFactory<KnowledgeSourceTranslationFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['locale', 'title'];

    public function knowledgeSource(): BelongsTo
    {
        return $this->belongsTo(KnowledgeSource::class);
    }
}
