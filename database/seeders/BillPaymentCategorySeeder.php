<?php

namespace Database\Seeders;

use App\Models\BillPaymentCategory;
use Illuminate\Database\Seeder;

class BillPaymentCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            [
                'code' => 'airtime',
                'name' => 'Airtime',
                'description' => 'Mobile airtime recharge',
                'is_active' => true,
            ],
            [
                'code' => 'data',
                'name' => 'Data',
                'description' => 'Mobile data plans and bundles',
                'is_active' => true,
            ],
            [
                'code' => 'electricity',
                'name' => 'Electricity',
                'description' => 'Electricity bill payments',
                'is_active' => true,
            ],
            [
                'code' => 'cable_tv',
                'name' => 'Cable TV',
                'description' => 'Cable TV and streaming subscriptions',
                'is_active' => true,
            ],
            [
                'code' => 'betting',
                'name' => 'Betting',
                'description' => 'Sports betting platform funding',
                'is_active' => true,
            ],
            [
                'code' => 'internet',
                'name' => 'Internet Subscription',
                'description' => 'Internet router subscriptions',
                'is_active' => true,
            ],
        ];

        foreach ($categories as $category) {
            BillPaymentCategory::firstOrCreate(
                ['code' => $category['code']],
                $category
            );
        }
    }
}
