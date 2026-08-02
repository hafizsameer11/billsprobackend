<?php

namespace Tests\Feature;

use App\Models\PlatformRate;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Admin\CardLoadProfitBackfillService;
use App\Services\Admin\DatabaseBackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CardLoadProfitBackfillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(DatabaseBackupService::class, function ($mock): void {
            $mock->shouldReceive('backupMysql')->andReturn(storage_path('app/backups/test_backup.sql.gz'));
        });

        $this->seedVisaFundRate();
    }

    private function seedVisaFundRate(): void
    {
        $m = new PlatformRate([
            'category' => 'virtual_card',
            'service_key' => 'visa_fund',
            'exchange_rate_ngn_per_usd' => 1500.0,
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

    public function test_first_recharge_backfills_separate_metadata_field_only(): void
    {
        Http::fake([
            '*/admin/transactions*' => Http::response([
                'status' => true,
                'visa_wallet_balance' => 100,
                'transactions' => [],
            ], 200),
        ]);

        $user = User::factory()->create();
        $originalWalletCharge = [
            'principal_usd' => 10.0,
            'charge_ngn' => 18000.0,
            'exchange_rate_ngn_per_usd' => 1500.0,
            'payment_wallet_type' => 'naira_wallet',
        ];

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
            'reference' => 'FUNDHIST001',
            'description' => 'Historical card load',
            'metadata' => [
                'principal_usd' => 10.0,
                'wallet_charge' => $originalWalletCharge,
                'payment_wallet_type' => 'naira_wallet',
                'exchange_rate_ngn_per_usd' => 1500.0,
                'card_scheme' => 'visa',
            ],
        ]);

        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/pagocards-wallet/recharges', [
            'ngn_spent' => 710000,
            'usd_credited' => 499,
        ])->assertOk();

        $backfill = $response->json('data.historical_backfill');
        $this->assertFalse($backfill['skipped']);
        $this->assertSame(1, $backfill['processed']);
        $this->assertSame(CardLoadProfitBackfillService::METADATA_KEY, $backfill['metadata_key']);

        $fresh = $tx->fresh();
        $meta = $fresh->metadata;
        $this->assertArrayHasKey('profit_snapshot_backfill', $meta);
        $this->assertArrayNotHasKey('profit_snapshot', $meta);
        $this->assertEquals($originalWalletCharge, $meta['wallet_charge']);
        $this->assertSame('card_load_profit_backfill', $meta['profit_snapshot_backfill']['source']);
        $this->assertGreaterThan(0, (float) $meta['profit_snapshot_backfill']['net_profit_ngn']);

        $this->assertDatabaseHas('pagocards_wallet_recharges', [
            'history_backfill_count' => 1,
        ]);
    }

    public function test_second_recharge_does_not_run_backfill_again(): void
    {
        Http::fake([
            '*/admin/transactions*' => Http::response([
                'status' => true,
                'visa_wallet_balance' => 100,
                'transactions' => [],
            ], 200),
        ]);

        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/pagocards-wallet/recharges', [
            'ngn_spent' => 710000,
            'usd_credited' => 499,
        ])->assertOk();

        $second = $this->postJson('/api/admin/pagocards-wallet/recharges', [
            'ngn_spent' => 800000,
            'usd_credited' => 500,
        ])->assertOk();

        $this->assertNull($second->json('data.historical_backfill'));
    }
}
