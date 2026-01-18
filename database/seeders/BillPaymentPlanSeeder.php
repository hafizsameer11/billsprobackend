<?php

namespace Database\Seeders;

use App\Models\BillPaymentProvider;
use App\Models\BillPaymentPlan;
use Illuminate\Database\Seeder;

class BillPaymentPlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get Data Providers
        $mtnData = BillPaymentProvider::where('code', 'MTN')->whereHas('category', function($q) {
            $q->where('code', 'data');
        })->first();
        
        $gloData = BillPaymentProvider::where('code', 'GLO')->whereHas('category', function($q) {
            $q->where('code', 'data');
        })->first();
        
        $airtelData = BillPaymentProvider::where('code', 'AIRTEL')->whereHas('category', function($q) {
            $q->where('code', 'data');
        })->first();

        // Get Cable TV Providers
        $dstv = BillPaymentProvider::where('code', 'DSTV')->first();
        $showmax = BillPaymentProvider::where('code', 'SHOWMAX')->first();
        $gotv = BillPaymentProvider::where('code', 'GOTV')->first();

        // MTN Data Plans
        $mtnPlans = [
            ['code' => 'MTN_100MB', 'name' => '100MB', 'amount' => 100, 'data_amount' => '100MB', 'validity' => '1 day'],
            ['code' => 'MTN_200MB', 'name' => '200MB', 'amount' => 200, 'data_amount' => '200MB', 'validity' => '3 days'],
            ['code' => 'MTN_500MB', 'name' => '500MB', 'amount' => 500, 'data_amount' => '500MB', 'validity' => '7 days'],
            ['code' => 'MTN_1GB', 'name' => '1GB', 'amount' => 1000, 'data_amount' => '1GB', 'validity' => '30 days'],
            ['code' => 'MTN_2GB', 'name' => '2GB', 'amount' => 2000, 'data_amount' => '2GB', 'validity' => '30 days'],
            ['code' => 'MTN_5GB', 'name' => '5GB', 'amount' => 5000, 'data_amount' => '5GB', 'validity' => '30 days'],
            ['code' => 'MTN_10GB', 'name' => '10GB', 'amount' => 10000, 'data_amount' => '10GB', 'validity' => '30 days'],
        ];

        // GLO Data Plans (same structure)
        $gloPlans = [
            ['code' => 'GLO_100MB', 'name' => '100MB', 'amount' => 100, 'data_amount' => '100MB', 'validity' => '1 day'],
            ['code' => 'GLO_200MB', 'name' => '200MB', 'amount' => 200, 'data_amount' => '200MB', 'validity' => '3 days'],
            ['code' => 'GLO_500MB', 'name' => '500MB', 'amount' => 500, 'data_amount' => '500MB', 'validity' => '7 days'],
            ['code' => 'GLO_1GB', 'name' => '1GB', 'amount' => 1000, 'data_amount' => '1GB', 'validity' => '30 days'],
            ['code' => 'GLO_2GB', 'name' => '2GB', 'amount' => 2000, 'data_amount' => '2GB', 'validity' => '30 days'],
            ['code' => 'GLO_5GB', 'name' => '5GB', 'amount' => 5000, 'data_amount' => '5GB', 'validity' => '30 days'],
            ['code' => 'GLO_10GB', 'name' => '10GB', 'amount' => 10000, 'data_amount' => '10GB', 'validity' => '30 days'],
        ];

        // Airtel Data Plans (same structure)
        $airtelPlans = [
            ['code' => 'AIRTEL_100MB', 'name' => '100MB', 'amount' => 100, 'data_amount' => '100MB', 'validity' => '1 day'],
            ['code' => 'AIRTEL_200MB', 'name' => '200MB', 'amount' => 200, 'data_amount' => '200MB', 'validity' => '3 days'],
            ['code' => 'AIRTEL_500MB', 'name' => '500MB', 'amount' => 500, 'data_amount' => '500MB', 'validity' => '7 days'],
            ['code' => 'AIRTEL_1GB', 'name' => '1GB', 'amount' => 1000, 'data_amount' => '1GB', 'validity' => '30 days'],
            ['code' => 'AIRTEL_2GB', 'name' => '2GB', 'amount' => 2000, 'data_amount' => '2GB', 'validity' => '30 days'],
            ['code' => 'AIRTEL_5GB', 'name' => '5GB', 'amount' => 5000, 'data_amount' => '5GB', 'validity' => '30 days'],
            ['code' => 'AIRTEL_10GB', 'name' => '10GB', 'amount' => 10000, 'data_amount' => '10GB', 'validity' => '30 days'],
        ];

        // DSTV Plans
        $dstvPlans = [
            ['code' => 'DSTV_COMPACT', 'name' => 'Compact', 'amount' => 7900, 'validity' => '1 month'],
            ['code' => 'DSTV_COMPACT_PLUS', 'name' => 'Compact Plus', 'amount' => 12900, 'validity' => '1 month'],
            ['code' => 'DSTV_PREMIUM', 'name' => 'Premium', 'amount' => 24500, 'validity' => '1 month'],
            ['code' => 'DSTV_ASIAN', 'name' => 'Asian', 'amount' => 1900, 'validity' => '1 month'],
            ['code' => 'DSTV_PIDGIN', 'name' => 'Pidgin', 'amount' => 2650, 'validity' => '1 month'],
        ];

        // Showmax Plans
        $showmaxPlans = [
            ['code' => 'SHOWMAX_MOBILE', 'name' => 'Mobile', 'amount' => 1200, 'validity' => '1 month'],
            ['code' => 'SHOWMAX_STANDARD', 'name' => 'Standard', 'amount' => 2900, 'validity' => '1 month'],
            ['code' => 'SHOWMAX_PRO', 'name' => 'Pro', 'amount' => 4900, 'validity' => '1 month'],
        ];

        // GOtv Plans
        $gotvPlans = [
            ['code' => 'GOTV_SMALLIE', 'name' => 'Smallie', 'amount' => 1650, 'validity' => '1 month'],
            ['code' => 'GOTV_JINJA', 'name' => 'Jinja', 'amount' => 2650, 'validity' => '1 month'],
            ['code' => 'GOTV_JINJA_PLUS', 'name' => 'Jinja Plus', 'amount' => 3250, 'validity' => '1 month'],
            ['code' => 'GOTV_MAX', 'name' => 'Max', 'amount' => 5650, 'validity' => '1 month'],
        ];

        // Seed Data Plans
        if ($mtnData) {
            foreach ($mtnPlans as $plan) {
                BillPaymentPlan::firstOrCreate(
                    ['provider_id' => $mtnData->id, 'code' => $plan['code']],
                    array_merge($plan, ['provider_id' => $mtnData->id, 'currency' => 'NGN', 'is_active' => true])
                );
            }
        }

        if ($gloData) {
            foreach ($gloPlans as $plan) {
                BillPaymentPlan::firstOrCreate(
                    ['provider_id' => $gloData->id, 'code' => $plan['code']],
                    array_merge($plan, ['provider_id' => $gloData->id, 'currency' => 'NGN', 'is_active' => true])
                );
            }
        }

        if ($airtelData) {
            foreach ($airtelPlans as $plan) {
                BillPaymentPlan::firstOrCreate(
                    ['provider_id' => $airtelData->id, 'code' => $plan['code']],
                    array_merge($plan, ['provider_id' => $airtelData->id, 'currency' => 'NGN', 'is_active' => true])
                );
            }
        }

        // Seed Cable TV Plans
        if ($dstv) {
            foreach ($dstvPlans as $plan) {
                BillPaymentPlan::firstOrCreate(
                    ['provider_id' => $dstv->id, 'code' => $plan['code']],
                    array_merge($plan, ['provider_id' => $dstv->id, 'currency' => 'NGN', 'is_active' => true])
                );
            }
        }

        if ($showmax) {
            foreach ($showmaxPlans as $plan) {
                BillPaymentPlan::firstOrCreate(
                    ['provider_id' => $showmax->id, 'code' => $plan['code']],
                    array_merge($plan, ['provider_id' => $showmax->id, 'currency' => 'NGN', 'is_active' => true])
                );
            }
        }

        if ($gotv) {
            foreach ($gotvPlans as $plan) {
                BillPaymentPlan::firstOrCreate(
                    ['provider_id' => $gotv->id, 'code' => $plan['code']],
                    array_merge($plan, ['provider_id' => $gotv->id, 'currency' => 'NGN', 'is_active' => true])
                );
            }
        }
    }
}
