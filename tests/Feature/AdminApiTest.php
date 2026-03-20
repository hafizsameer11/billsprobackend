<?php

namespace Tests\Feature;

use App\Models\FiatWallet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_cannot_access_admin_stats(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        Sanctum::actingAs($user);

        $this->getJson('/api/admin/stats')->assertForbidden();
    }

    public function test_admin_can_access_admin_stats(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/stats')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_suspended_user_cannot_access_wallet_balance(): void
    {
        $user = User::factory()->create([
            'account_status' => 'suspended',
            'suspended_at' => now(),
        ]);
        Sanctum::actingAs($user);

        $this->getJson('/api/wallet/balance')
            ->assertForbidden()
            ->assertJsonPath('code', 'ACCOUNT_SUSPENDED');
    }

    public function test_fiat_adjustment_creates_transaction_and_audit(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();
        $wallet = FiatWallet::create([
            'user_id' => $user->id,
            'currency' => 'NGN',
            'country_code' => 'NG',
            'balance' => '1000',
            'locked_balance' => '0',
            'is_active' => true,
        ]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/adjustments/fiat', [
            'fiat_wallet_id' => $wallet->id,
            'direction' => 'credit',
            'amount' => '50',
            'reason' => 'Test reconciliation',
        ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'type' => 'admin_credit',
        ]);
        $this->assertDatabaseHas('admin_audit_logs', [
            'admin_user_id' => $admin->id,
            'action' => 'adjustment.fiat',
        ]);
    }
}
