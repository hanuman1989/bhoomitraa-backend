<?php

namespace App\Models;

use Database\Factories\KnowledgeSourceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\Storage;

class KnowledgeSource extends Model
{
    /** @use HasFactory<KnowledgeSourceFactory> */
    use HasFactory;

    /** @var list<string> */
    public const SOURCE_TYPES = ['government_publication', 'research_paper', 'journal_article', 'report', 'book', 'manual', 'guideline', 'website', 'other'];

    /** @var list<string> */
    public const IDENTIFIER_TYPES = ['doi', 'isbn', 'issn', 'report_number', 'other'];

    /** @var list<string> */
    public const VERIFICATION_STATUSES = ['pending', 'verified', 'rejected', 'outdated'];

    /** @var list<string> */
    protected $fillable = [
        'title', 'publisher', 'source_type', 'source_url', 'language', 'published_at',
        'publication_year', 'reference_identifier', 'identifier_type', 'description', 'license_notes',
    ];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'published_at' => 'date', 'publication_year' => 'integer', 'is_active' => 'boolean',
            'verified_at' => 'datetime', 'last_checked_at' => 'datetime', 'next_review_at' => 'datetime',
        ];
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(KnowledgeSourceAttachment::class);
    }

    public function seeds(): HasMany
    {
        return $this->hasMany(Seed::class, 'source_id');
    }

    public function fertilizers(): HasMany
    {
        return $this->hasMany(Fertilizer::class, 'source_id');
    }

    public function seedPrices(): HasMany
    {
        return $this->hasMany(SeedPrice::class, 'source_id');
    }

    public function seedRates(): HasMany
    {
        return $this->hasMany(CropSeedRate::class, 'source_id');
    }

    public function yieldBenchmarks(): HasMany
    {
        return $this->hasMany(CropYieldBenchmark::class, 'source_id');
    }

    public function fertilizerRecommendations(): HasMany
    {
        return $this->hasMany(FertilizerRecommendation::class, 'source_id');
    }

    public function translations(): HasMany
    {
        return $this->hasMany(KnowledgeSourceTranslation::class);
    }

    public function translatedTitle(?string $locale = null): string
    {
        if (! $this->relationLoaded('translations')) {
            return $this->title;
        }

        return $this->translations->firstWhere('locale', $locale ?? app()->getLocale())?->title
            ?? $this->translations->firstWhere('locale', config('languages.default'))?->title
            ?? $this->title;
    }

    public function scopeWithLocalizedTitle(Builder $query): void
    {
        $query->leftJoin('knowledge_source_translations as requested_translation', function (JoinClause $join): void {
            $join->on('requested_translation.knowledge_source_id', '=', 'knowledge_sources.id')
                ->where('requested_translation.locale', app()->getLocale());
        })->leftJoin('knowledge_source_translations as default_translation', function (JoinClause $join): void {
            $join->on('default_translation.knowledge_source_id', '=', 'knowledge_sources.id')
                ->where('default_translation.locale', config('languages.default'));
        })->select('knowledge_sources.*')
            ->selectRaw('COALESCE(requested_translation.title, default_translation.title, knowledge_sources.title) as localized_title');
    }

    /** @param array<string, array{title: string}> $translations */
    public function saveTranslations(array $translations): bool
    {
        $changed = false;
        foreach ($translations as $locale => $translation) {
            $saved = $this->translations()->updateOrCreate(['locale' => $locale], $translation);
            $changed = $saved->wasRecentlyCreated || $saved->wasChanged('title') || $changed;
        }

        return $changed;
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'verified_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'updated_by');
    }

    /** Invalidate the current decision; the MVP does not store review history. */
    public function resetVerification(): void
    {
        $this->verification_status = 'pending';
        $this->is_active = false;
        $this->verified_by = null;
        $this->verified_at = null;
        $this->last_checked_at = null;
        $this->next_review_at = null;
        $this->review_notes = null;
        $this->rejection_reason = null;
    }

    /** @param Collection<int, KnowledgeSourceAttachment>|null $attachments */
    public function hasUsableReference(?int $excludingAttachmentId = null, ?Collection $attachments = null): bool
    {
        if (filled($this->source_url) || filled($this->reference_identifier)) {
            return true;
        }

        $attachments ??= $this->attachments()->when($excludingAttachmentId !== null,
            fn (Builder $query): Builder => $query->whereKeyNot($excludingAttachmentId))->get();
        foreach ($attachments as $attachment) {
            if ($attachment->id === $excludingAttachmentId) {
                continue;
            }
            if (Storage::disk(config('filesystems.knowledge_sources_disk'))->exists($attachment->file_path)) {
                return true;
            }
        }

        return false;
    }
}
