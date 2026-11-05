<?php

namespace Database\Factories;

use App\Models\AdminUser;
use App\Models\KnowledgeSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<KnowledgeSource> */
class KnowledgeSourceFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(),
            'publisher' => fake()->company(),
            'source_type' => 'report',
            'source_url' => 'https://example.org/agriculture/report',
            'language' => 'en',
            'verification_status' => 'pending',
            'is_active' => false,
        ];
    }

    public function verified(): static
    {
        return $this->state(fn (): array => [
            'verification_status' => 'verified', 'verified_by' => AdminUser::factory()->superUser(),
            'verified_at' => now(), 'last_checked_at' => now(),
        ]);
    }

    public function active(): static
    {
        return $this->verified()->state(fn (): array => ['is_active' => true]);
    }
}
