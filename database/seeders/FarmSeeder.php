<?php

namespace Database\Seeders;

use App\Models\Farm;
use App\Models\Region;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Seeder;

class FarmSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }
        $district = Region::query()->where('region_type', 'district')->where('is_active', true)
            ->whereHas('parent', fn (Builder $state): Builder => $state->where('region_type', 'state')->where('is_active', true))->first();
        $user = User::query()->where('status', true)->first();
        if ($district === null || $user === null || $user->farms()->exists()) {
            return;
        }
        Farm::factory()->for($user)->inDistrict($district)->create(['name' => 'Demo farm']);
    }
}
