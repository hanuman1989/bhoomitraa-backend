<?php

namespace Database\Factories;

use App\Models\KnowledgeSource;
use App\Models\KnowledgeSourceTranslation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KnowledgeSourceTranslation>
 */
class KnowledgeSourceTranslationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'knowledge_source_id' => KnowledgeSource::factory(),
            'locale' => 'en',
            'title' => fake()->sentence(),
        ];
    }
}
