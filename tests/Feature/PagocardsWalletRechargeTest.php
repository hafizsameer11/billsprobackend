<?php

namespace Tests\Feature;

use App\Models\PagocardsWalletRecharge;
use App\Models\User;
use App\Services\Admin\DatabaseBackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PagocardsWalletRechargeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(DatabaseBackupService::class, function ($mock): void {
            $mock->shouldReceive('backupMysql')->andReturn(storage_path('app/backups/test_backup.sql.gz'));
        });
    }

    public function test_non_admin_cannot_access_pagocards_wallet_summary(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        Sanctum::actingAs($user);

        $this->getJson('/api/admin/pagocards-wallet/summary')->assertForbidden();
    }

    public function test_admin_can_log_recharge_and_fetch_summary(): void
    {
        Http::fake([
            '*/admin/transactions*' => Http::response([
                'status' => true,
                'visa_wallet_balance' => 1234.56,
                'master_wallet_balance' => 0,
                'transactions' => [],
            ], 200),
        ]);

        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/pagocards-wallet/recharges', [
            'ngn_spent' => 710000,
            'usd_credited' => 499,
            'usd_gross' => 508,
            'notes' => 'Bybit top-up',
        ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertEqualsWithDelta(
            710000 / 499,
            (float) $response->json('data.recharge.true_rate_ngn_per_usd'),
            0.01
        );

        $this->assertDatabaseHas('pagocards_wallet_recharges', [
            'ngn_spent' => '710000.00',
            'usd_credited' => '499.0000',
            'created_by' => $admin->id,
        ]);

        $summary = $this->getJson('/api/admin/pagocards-wallet/summary')
            ->assertOk()
            ->json('data');

        $this->assertEqualsWithDelta(710000 / 499, (float) $summary['current_true_rate'], 0.01);
        $this->assertEquals(1234.56, $summary['pagocards_wallet']['visa_wallet_balance']);

        $this->getJson('/api/admin/pagocards-wallet/recharges')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.notes', 'Bybit top-up');
    }

    public function test_latest_recharge_replaces_current_true_rate(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin);

        Http::fake([
            '*/admin/transactions*' => Http::response([
                'status' => true,
                'visa_wallet_balance' => 500,
                'transactions' => [],
            ], 200),
        ]);

        $this->postJson('/api/admin/pagocards-wallet/recharges', [
            'ngn_spent' => 710000,
            'usd_credited' => 499,
        ])->assertOk();

        $this->postJson('/api/admin/pagocards-wallet/recharges', [
            'ngn_spent' => 800000,
            'usd_credited' => 500,
        ])->assertOk();

        $summary = $this->getJson('/api/admin/pagocards-wallet/summary')
            ->assertOk()
            ->json('data');

        $this->assertEqualsWithDelta(800000 / 500, (float) $summary['current_true_rate'], 0.01);

        $this->assertSame(2, PagocardsWalletRecharge::query()->count());
    }
}
