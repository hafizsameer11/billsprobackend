<?php

namespace Tests\Unit;

use App\Models\PlatformRate;
use App\Services\Admin\PricingCatalogService;
use Database\Seeders\BillPaymentCategorySeeder;
use Database\Seeders\CommissionTablesSeeder;
use Database\Seeders\PdfPricingDefaultsSeeder;
use Database\Seeders\PlatformRateSeeder;
use Database\Seeders\WalletCurrencySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PricingCatalogServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function seedPricing(): void
    {
        $this->seed([
            BillPaymentCategorySeeder::class,
            WalletCurrencySeeder::class,
            PlatformRateSeeder::class,
            CommissionTablesSeeder::class,
            PdfPricingDefaultsSeeder::class,
        ]);
    }

    public function test_catalog_follows_pdf_order_and_excludes_scaffold_rows(): void
    {
        $this->seedPricing();

        $catalog = app(PricingCatalogService::class)->buildCatalog();
        $labels = collect($catalog)->pluck('label')->all();

        $this->assertSame('Bank Transfer', $labels[0]);
        $this->assertContains('Crypto Buy/Sell', $labels);
        $this->assertContains('SOL Send (solana)', $labels);
        $this->assertNotContains('Fiat · bill_payment', $labels);
        $this->assertNotContains('Fiat · bill_payment · airtime', $labels);
        $this->assertNotContains('Crypto · buy · BTC · bitcoin', $labels);
    }

    public function test_mastercard_funding_catalog_matches_pdf_rule_text(): void
    {
        $this->seedPricing();

        $catalog = app(PricingCatalogService::class)->buildCatalog();
        $row = collect($catalog)->firstWhere('label', 'Mastercard Card Funding');

        $this->assertNotNull($row);
        $this->assertSame('$1.00 + 1%', $row['provider_cost_display']);
        $this->assertSame('$1.00 + ₦80 FX markup', $row['billspro_charge_display']);
        $this->assertSame('₦80 FX spread', $row['estimated_profit_display']);
    }

    public function test_palmpay_deposit_catalog_has_no_usd(): void
    {
        $this->seedPricing();

        $catalog = app(PricingCatalogService::class)->buildCatalog();
        $row = collect($catalog)->firstWhere('label', 'Wallet Deposit (PalmPay)');

        $this->assertNotNull($row);
        $this->assertStringNotContainsString('$', $row['provider_cost_display']);
        $this->assertSame('0.7% cap ₦700', $row['provider_cost_display']);
        $this->assertSame('₦0', $row['billspro_charge_display']);
        $this->assertSame('Loss leader', $row['estimated_profit_display']);
    }

    public function test_crypto_receive_matches_pdf(): void
    {
        $this->seedPricing();

        $row = collect(app(PricingCatalogService::class)->buildCatalog())
            ->firstWhere('label', 'Crypto Receive');

        $this->assertNotNull($row);
        $this->assertSame('$0', $row['provider_cost_display']);
        $this->assertSame('$1.00', $row['billspro_charge_display']);
        $this->assertSame('$1.00', $row['estimated_profit_display']);
    }
}
