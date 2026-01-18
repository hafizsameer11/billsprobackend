<?php

namespace Database\Seeders;

use App\Models\BillPaymentCategory;
use App\Models\BillPaymentProvider;
use Illuminate\Database\Seeder;

class BillPaymentProviderSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get categories
        $airtimeCategory = BillPaymentCategory::where('code', 'airtime')->first();
        $dataCategory = BillPaymentCategory::where('code', 'data')->first();
        $electricityCategory = BillPaymentCategory::where('code', 'electricity')->first();
        $cableTvCategory = BillPaymentCategory::where('code', 'cable_tv')->first();
        $bettingCategory = BillPaymentCategory::where('code', 'betting')->first();
        $internetCategory = BillPaymentCategory::where('code', 'internet')->first();

        // Airtime/Data Providers (MTN, GLO, Airtel)
        $airtimeDataProviders = [
            [
                'category_id' => $airtimeCategory->id,
                'code' => 'MTN',
                'name' => 'MTN',
                'logo_url' => '/billpayments/mtn.png',
                'country_code' => 'NG',
                'currency' => 'NGN',
                'is_active' => true,
            ],
            [
                'category_id' => $airtimeCategory->id,
                'code' => 'GLO',
                'name' => 'GLO',
                'logo_url' => '/billpayments/glo.png',
                'country_code' => 'NG',
                'currency' => 'NGN',
                'is_active' => true,
            ],
            [
                'category_id' => $airtimeCategory->id,
                'code' => 'AIRTEL',
                'name' => 'Airtel',
                'logo_url' => '/billpayments/airtel.png',
                'country_code' => 'NG',
                'currency' => 'NGN',
                'is_active' => true,
            ],
        ];

        // Data Providers (same as airtime)
        $dataProviders = [
            [
                'category_id' => $dataCategory->id,
                'code' => 'MTN',
                'name' => 'MTN',
                'logo_url' => '/billpayments/mtn.png',
                'country_code' => 'NG',
                'currency' => 'NGN',
                'is_active' => true,
            ],
            [
                'category_id' => $dataCategory->id,
                'code' => 'GLO',
                'name' => 'GLO',
                'logo_url' => '/billpayments/glo.png',
                'country_code' => 'NG',
                'currency' => 'NGN',
                'is_active' => true,
            ],
            [
                'category_id' => $dataCategory->id,
                'code' => 'AIRTEL',
                'name' => 'Airtel',
                'logo_url' => '/billpayments/airtel.png',
                'country_code' => 'NG',
                'currency' => 'NGN',
                'is_active' => true,
            ],
        ];

        // Electricity Providers
        $electricityProviders = [
            [
                'category_id' => $electricityCategory->id,
                'code' => 'IKEJA',
                'name' => 'Ikeja Electric',
                'logo_url' => '/billpayments/ikeja.png',
                'country_code' => 'NG',
                'currency' => 'NGN',
                'is_active' => true,
            ],
            [
                'category_id' => $electricityCategory->id,
                'code' => 'IBADAN',
                'name' => 'Ibadan Electric',
                'logo_url' => '/billpayments/ibadan.png',
                'country_code' => 'NG',
                'currency' => 'NGN',
                'is_active' => true,
            ],
            [
                'category_id' => $electricityCategory->id,
                'code' => 'ABUJA',
                'name' => 'Abuja Electric',
                'logo_url' => '/billpayments/abuja.png',
                'country_code' => 'NG',
                'currency' => 'NGN',
                'is_active' => true,
            ],
        ];

        // Cable TV Providers
        $cableTvProviders = [
            [
                'category_id' => $cableTvCategory->id,
                'code' => 'DSTV',
                'name' => 'DSTV',
                'logo_url' => '/billpayments/dstv.png',
                'country_code' => 'NG',
                'currency' => 'NGN',
                'is_active' => true,
            ],
            [
                'category_id' => $cableTvCategory->id,
                'code' => 'SHOWMAX',
                'name' => 'Showmax',
                'logo_url' => '/billpayments/showmax.png',
                'country_code' => 'NG',
                'currency' => 'NGN',
                'is_active' => true,
            ],
            [
                'category_id' => $cableTvCategory->id,
                'code' => 'GOTV',
                'name' => 'GOtv',
                'logo_url' => '/billpayments/gotv.png',
                'country_code' => 'NG',
                'currency' => 'NGN',
                'is_active' => true,
            ],
        ];

        // Betting Providers
        $bettingProviders = [
            [
                'category_id' => $bettingCategory->id,
                'code' => '1XBET',
                'name' => '1xBet',
                'logo_url' => '/billpayments/1xbet.png',
                'country_code' => 'NG',
                'currency' => 'NGN',
                'is_active' => true,
            ],
            [
                'category_id' => $bettingCategory->id,
                'code' => 'BET9JA',
                'name' => 'Bet9ja',
                'logo_url' => '/billpayments/bet9ja.png',
                'country_code' => 'NG',
                'currency' => 'NGN',
                'is_active' => true,
            ],
            [
                'category_id' => $bettingCategory->id,
                'code' => 'SPORTBET',
                'name' => 'SportBet',
                'logo_url' => '/billpayments/sportbet.png',
                'country_code' => 'NG',
                'currency' => 'NGN',
                'is_active' => true,
            ],
        ];

        $allProviders = array_merge(
            $airtimeDataProviders,
            $dataProviders,
            $electricityProviders,
            $cableTvProviders,
            $bettingProviders
        );

        foreach ($allProviders as $provider) {
            BillPaymentProvider::firstOrCreate(
                [
                    'category_id' => $provider['category_id'],
                    'code' => $provider['code'],
                ],
                $provider
            );
        }
    }
}
