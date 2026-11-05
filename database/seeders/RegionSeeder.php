<?php

namespace Database\Seeders;

use App\Models\Region;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RegionSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $country = Region::firstOrCreate(['code' => 'IN', 'region_type' => 'country'], ['is_active' => true]);
            foreach (['en' => 'India', 'hi' => 'भारत'] as $locale => $name) {
                $country->translations()->firstOrCreate(['locale' => $locale], ['name' => $name]);
            }
        });
    }
}
