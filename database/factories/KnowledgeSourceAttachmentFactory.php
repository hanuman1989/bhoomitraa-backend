<?php

namespace Database\Factories;

use App\Models\KnowledgeSource;
use App\Models\KnowledgeSourceAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<KnowledgeSourceAttachment> */
class KnowledgeSourceAttachmentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'knowledge_source_id' => KnowledgeSource::factory(),
            'file_name' => 'report.pdf',
            'file_path' => 'knowledge-sources/'.Str::uuid().'.pdf',
            'file_type' => 'application/pdf',
            'file_size' => 100,
        ];
    }
}
