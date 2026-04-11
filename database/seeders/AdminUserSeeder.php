<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Default dashboard admin (Sanctum login; must pass email verification gate).
     */
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'admin@gmail.com'],
            [
                'name' => 'BillsPro Admin',
                'first_name' => 'BillsPro',
                'last_name' => 'Admin',
                'password' => Hash::make('11221122'),
                'country_code' => 'NG',
                'email_verified' => true,
                'phone_verified' => false,
                'kyc_completed' => false,
                'is_admin' => true,
                'account_status' => 'active',
            ]
        );
    }
}
