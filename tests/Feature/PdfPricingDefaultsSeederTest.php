<?php

namespace Tests\Feature;

use App\Models\PlatformRate;
use App\Models\ServiceProfitSetting;
use Database\Seeders\BillPaymentCategorySeeder;
use Database\Seeders\CommissionTablesSeeder;
use Database\Seeders\PdfPricingDefaultsSeeder;
use Database\Seeders\PlatformRateSeeder;
use Database\Seeders\WalletCurrencySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PdfPricingDefaultsSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_applies_pdf_bank_transfer_and_crypto_receive_fees(): void
    {
        $this->seed([
            BillPaymentCategorySeeder::class,
            WalletCurrencySeeder::class,
            PlatformRateSeeder::class,
            CommissionTablesSeeder::class,
            PdfPricingDefaultsSeeder::class,
        ]);

        $withdrawal = PlatformRate::query()->where('slug', 'fiat|withdrawal|||')->first();
        $this->assertNotNull($withdrawal);
        $this->assertEquals(50.0, (float) $withdrawal->fixed_fee_ngn);
        $this->assertEquals(25.0, (float) $withdrawal->provider_cost_ngn);
        $this->assertSame('Bank Transfer', $withdrawal->display_label);

        $deposit = PlatformRate::query()->where('slug', 'fiat|deposit|||')->first();
        $this->assertNotNull($deposit);
        $this->assertEquals(0.0, (float) $deposit->fixed_fee_ngn);
        $this->assertEquals(0.7, (float) $deposit->provider_pct);
        $this->assertEquals(700.0, (float) $deposit->provider_pct_cap_ngn);

        $cryptoDeposit = PlatformRate::query()->where('slug', 'crypto|deposit|||')->first();
        $this->assertNotNull($cryptoDeposit);
        $this->assertEquals(1.0, (float) $cryptoDeposit->fee_usd);

        $ethSend = PlatformRate::query()
            ->where('slug', 'crypto|withdrawal||ETH|ethereum')
            ->first();
        $this->assertNotNull($ethSend);
        $this->assertEquals(1.0, (float) $ethSend->fee_usd);
        $this->assertEquals(0.5, (float) $ethSend->provider_cost_usd);

        $usdtTronSend = PlatformRate::query()
            ->where('slug', 'crypto|withdrawal||USDT_TRON|tron')
            ->first();
        $this->assertNotNull($usdtTronSend);
        $this->assertEquals(1.0, (float) $usdtTronSend->fee_usd);

        $visaFund = PlatformRate::query()->where('slug', 'virtual_card|visa_fund|||')->first();
        $this->assertNotNull($visaFund);
        $this->assertEquals(1.0, (float) $visaFund->fee_usd);
        $this->assertEquals(80.0, (float) $visaFund->fixed_fee_ngn);
        $this->assertEquals(2.0, (float) $visaFund->provider_pct);

        $profit = ServiceProfitSetting::query()->where('service_key', 'withdrawal')->first();
        $this->assertNotNull($profit);
        $this->assertSame('fiat|withdrawal|||', $profit->linked_rate_slug);
    }
}
