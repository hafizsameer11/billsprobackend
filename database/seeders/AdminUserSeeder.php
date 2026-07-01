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
        $password = (string) env('ADMIN_SEED_PASSWORD', '');
        if ($password === '' && app()->environment('production')) {
            throw new \RuntimeException('ADMIN_SEED_PASSWORD must be set to seed admin in production.');
        }
        if ($password === '') {
            $password = '11221122';
        }

        User::query()->updateOrCreate(
            ['email' => 'admin@gmail.com'],
            [
                'name' => 'BillsPro Admin',
                'first_name' => 'BillsPro',
                'last_name' => 'Admin',
                'password' => Hash::make($password),
                'country_code' => 'NG',
                'email_verified' => true,
                'phone_verified' => false,
                'kyc_completed' => false,
            ]
        )->forceFill([
            'is_admin' => true,
            'account_status' => 'active',
        ])->save();
    }
}