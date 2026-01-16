<?php

namespace Database\Seeders;

use App\Models\BankAccount;
use Illuminate\Database\Seeder;

class BankAccountSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $accounts = [
            [
                'bank_name' => 'Gratuity Bank',
                'account_number' => '113456789',
                'account_name' => 'Yellow card Financial',
                'currency' => 'NGN',
                'country_code' => 'NG',
                'is_active' => true,
                'metadata' => [
                    'swift_code' => null,
                    'routing_number' => null,
                ],
            ],
        ];

        foreach ($accounts as $account) {
            BankAccount::firstOrCreate(
                ['account_number' => $account['account_number']],
                $account
            );
        }
    }
}
