<?php

namespace Database\Seeders;

use App\Models\AdminUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Validator;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $credentials = Validator::make(config('auth.bootstrap_admin'), [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:12', 'max:255'],
        ])->validate();

        AdminUser::firstOrCreate(
            ['email' => $credentials['email']],
            [
                'first_name' => $credentials['first_name'],
                'last_name' => $credentials['last_name'],
                'password' => $credentials['password'],
                'status' => true,
                'is_super_user' => true,
            ],
        );
    }
}
