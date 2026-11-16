<?php

namespace Database\Seeders;

use App\Models\Season;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SeasonSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            foreach ([
                'rabi' => ['start_month' => 10, 'end_month' => 3, 'icon' => 'sprout', 'names' => ['en' => 'Rabi', 'hi' => 'रबी']],
                'kharif' => ['start_month' => 6, 'end_month' => 10, 'icon' => 'sun', 'names' => ['en' => 'Kharif', 'hi' => 'खरीफ']],
                'zaid' => ['start_month' => 3, 'end_month' => 6, 'icon' => 'leaf', 'names' => ['en' => 'Zaid', 'hi' => 'ज़ायद']],
            ] as $slug => $values) {
                $season = Season::firstOrCreate(['slug' => $slug], array_diff_key($values, ['names' => true]));
                if (! $season->wasRecentlyCreated) {
                    continue;
                }
                foreach ($values['names'] as $locale => $name) {
                    $season->translations()->create(['locale' => $locale, 'name' => $name]);
                }
                $season->is_active = true;
                $season->save();
            }
        });
    }
}
