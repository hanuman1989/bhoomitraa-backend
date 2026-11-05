<?php

namespace App\Http\Resources\Admin;

use App\Models\KnowledgeSourceTranslation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KnowledgeSourceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'translations' => $this->whenLoaded('translations', fn (): object => (object) $this->translations
                ->mapWithKeys(fn (KnowledgeSourceTranslation $translation): array => [$translation->locale => ['title' => $translation->title]])->all()),
            'publisher' => $this->publisher,
            'source_type' => $this->source_type,
            'source_url' => $this->source_url,
            'language' => $this->language,
            'published_at' => $this->published_at?->format('Y-m-d'),
            'publication_year' => $this->publication_year,
            'reference_identifier' => $this->reference_identifier,
            'identifier_type' => $this->identifier_type,
            'description' => $this->description,
            'license_notes' => $this->license_notes,
            'verification_status' => $this->verification_status,
            'verified_by' => $this->verified_by,
            'verified_at' => $this->verified_at?->toISOString(),
            'last_checked_at' => $this->last_checked_at?->toISOString(),
            'next_review_at' => $this->next_review_at?->toISOString(),
            'review_notes' => $this->review_notes,
            'rejection_reason' => $this->rejection_reason,
            'is_active' => $this->is_active,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'added_at' => $this->created_at?->format('j M Y h:i A'),
            'attachments' => KnowledgeSourceAttachmentResource::collection($this->whenLoaded('attachments')),
            'verifier' => $this->whenLoaded('verifier', fn (): ?array => $this->verifier === null ? null : [
                'id' => $this->verifier->id, 'first_name' => $this->verifier->first_name, 'last_name' => $this->verifier->last_name,
            ]),
            'creator' => $this->whenLoaded('creator', fn (): ?array => $this->creator === null ? null : [
                'id' => $this->creator->id, 'first_name' => $this->creator->first_name, 'last_name' => $this->creator->last_name,
            ]),
            'updater' => $this->whenLoaded('updater', fn (): ?array => $this->updater === null ? null : [
                'id' => $this->updater->id, 'first_name' => $this->updater->first_name, 'last_name' => $this->updater->last_name,
            ]),
        ];
    }
}
