<?php

namespace Tests\Feature;

use App\Models\PagocardsWalletRecharge;
use App\Models\PlatformRate;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Admin\CardLoadProfitCalculator;
use App\Services\Admin\PagocardsWalletRechargeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CardLoadProfitCalculatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedVisaFundRate();
    }

    private function seedVisaFundRate(): void
    {
        $m = new PlatformRate([
            'category' => 'virtual_card',
            'service_key' => 'visa_fund',
            'sub_service_key' => null,
            'crypto_asset' => null,
            'network_key' => null,
            'exchange_rate_ngn_per_usd' => 1500.0,
            'fixed_fee_ngn' => 0,
            'fee_usd' => 2.0,
            'provider_cost_usd' => 1.0,
            'provider_pct' => 2.0,
            'is_active' => true,
        ]);
        PlatformRate::query()->updateOrCreate(
            ['slug' => PlatformRate::composeSlug($m)],
            array_merge($m->toArray(), ['slug' => PlatformRate::composeSlug($m)])
        );
    }

    public function test_recharge_computes_true_rate(): void
    {
        $service = app(PagocardsWalletRechargeService::class);
        $service->create([
            'ngn_spent' => 710000,
            'usd_credited' => 499,
            'recharged_at' => now(),
        ]);

        $this->assertEqualsWithDelta(710000 / 499, $service->currentTrueRate(), 0.01);
    }

    public function test_card_load_profit_matches_formula(): void
    {
        app(PagocardsWalletRechargeService::class)->create([
            'ngn_spent' => 710000,
            'usd_credited' => 499,
        ]);

        $charges = [
            'payment_wallet_type' => 'naira_wallet',
            'exchange_rate_ngn_per_usd' => 1500.0,
            'charge_ngn' => 18000.0,
            'processing_fee_ngn' => 3000.0,
        ];

        $snapshot = app(CardLoadProfitCalculator::class)->snapshotForNewFunding($charges, 10.0);

        $trueRate = 710000 / 499;
        $principalCost = round(10 * $trueRate, 2);
        $providerFee = round(1.2 * $trueRate, 2);
        $providerCost = round($principalCost + $providerFee, 2);
        $netProfit = round(18000 - $providerCost, 2);

        $this->assertNotNull($snapshot);
        $this->assertFalse($snapshot['missing_true_rate'] ?? false);
        $this->assertEqualsWithDelta(18000.0, $snapshot['revenue_ngn'], 0.01);
        $this->assertEqualsWithDelta($providerCost, $snapshot['provider_cost_ngn'], 0.01);
        $this->assertEqualsWithDelta($netProfit, $snapshot['net_profit_ngn'], 0.01);
    }

    public function test_new_recharge_does_not_change_old_transaction_snapshot(): void
    {
        $recharges = app(PagocardsWalletRechargeService::class);
        $calculator = app(CardLoadProfitCalculator::class);

        $recharges->create(['ngn_spent' => 710000, 'usd_credited' => 499]);

        $charges = [
            'payment_wallet_type' => 'naira_wallet',
            'exchange_rate_ngn_per_usd' => 1500.0,
            'charge_ngn' => 18000.0,
        ];
        $originalSnapshot = $calculator->snapshotForNewFunding($charges, 10.0);

        $user = User::factory()->create();
        $tx = Transaction::query()->create([
            'user_id' => $user->id,
            'transaction_id' => Transaction::generateTransactionId(),
            'type' => 'card_funding',
            'category' => 'virtual_card',
            'status' => 'completed',
            'currency' => 'NGN',
            'amount' => 18000,
            'fee' => 0,
            'total_amount' => 18000,
            'reference' => 'FUNDTEST001',
            'description' => 'Test card load',
            'metadata' => [
                'principal_usd' => 10.0,
                'wallet_charge' => $charges,
                'payment_wallet_type' => 'naira_wallet',
                'card_scheme' => 'visa',
                'profit_snapshot' => $originalSnapshot,
            ],
        ]);

        $recharges->create(['ngn_spent' => 800000, 'usd_credited' => 500]);

        $stored = $calculator->computeForFundingTransaction($tx->fresh());
        $this->assertEqualsWithDelta($originalSnapshot['net_profit_ngn'], $stored['net_profit_ngn'], 0.01);
        $this->assertEqualsWithDelta($originalSnapshot['true_rate_ngn_per_usd'], $stored['true_rate_ngn_per_usd'], 0.01);
    }

    public function test_missing_recharge_marks_snapshot_without_profit(): void
    {
        $charges = [
            'payment_wallet_type' => 'naira_wallet',
            'exchange_rate_ngn_per_usd' => 1500.0,
            'charge_ngn' => 18000.0,
        ];

        $snapshot = app(CardLoadProfitCalculator::class)->snapshotForNewFunding($charges, 10.0);

        $this->assertTrue($snapshot['missing_true_rate'] ?? false);
        $this->assertEquals(0, $snapshot['net_profit_ngn']);
    }
}
