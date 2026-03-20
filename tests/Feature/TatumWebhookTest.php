<?php

namespace Tests\Feature;

use App\Models\CryptoDepositAddress;
use App\Models\TatumRawWebhook;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VirtualAccount;
use App\Models\WalletCurrency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TatumWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_tatum_webhook_credits_balance_and_creates_transaction(): void
    {
        $user = User::factory()->create();

        $wc = WalletCurrency::query()->create([
            'blockchain' => 'ethereum',
            'currency' => 'ETH',
            'symbol' => 'ETH',
            'name' => 'Ethereum',
            'price' => 2000,
            'rate' => 2000,
            'decimals' => 18,
            'is_token' => false,
            'is_active' => true,
        ]);

        $va = VirtualAccount::query()->create([
            'user_id' => $user->id,
            'currency_id' => $wc->id,
            'blockchain' => 'ethereum',
            'currency' => 'ETH',
            'customer_id' => (string) $user->id,
            'account_id' => 'acc-test-'.uniqid(),
            'account_code' => 'code',
            'active' => true,
            'frozen' => false,
            'account_balance' => '0',
            'available_balance' => '0',
        ]);

        $ourAddress = '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

        CryptoDepositAddress::query()->create([
            'virtual_account_id' => $va->id,
            'user_wallet_id' => null,
            'blockchain' => 'ethereum',
            'currency' => 'eth',
            'address' => $ourAddress,
            'index' => 0,
            'private_key_encrypted' => null,
        ]);

        $payload = [
            'subscriptionType' => 'INCOMING_NATIVE_TX',
            'txId' => '0xtxhash1234567890123456789012345678901234567890123456789012345678',
            'address' => $ourAddress,
            'counterAddress' => '0xbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            'amount' => '0.5',
            'timestamp' => 1_700_000_000_000,
        ];

        $response = $this->postJson('/api/webhooks/tatum', $payload);

        $response->assertOk()
            ->assertJsonPath('message', 'Webhook received');

        $va->refresh();
        $this->assertEqualsWithDelta(0.5, (float) $va->available_balance, 0.0001);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'type' => 'crypto_deposit',
            'currency' => 'ETH',
        ]);

        $tx = Transaction::query()->where('type', 'crypto_deposit')->first();
        $this->assertNotNull($tx);
        $this->assertEquals('0xtxhash1234567890123456789012345678901234567890123456789012345678', $tx->metadata['tx_hash'] ?? null);

        // Idempotent second delivery
        $this->postJson('/api/webhooks/tatum', $payload)->assertOk();
        $this->assertEquals(1, Transaction::query()->where('type', 'crypto_deposit')->count());

        $this->assertEquals(2, TatumRawWebhook::query()->count());
    }
}
