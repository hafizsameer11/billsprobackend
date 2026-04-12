<?php

namespace Tests\Feature;

use App\Models\CryptoDepositAddress;
use App\Models\ReceivedAsset;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VirtualAccount;
use App\Models\WalletCurrency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncReceivedAssetTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_get_syncs_received_asset_from_crypto_deposit_transaction(): void
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
            'account_id' => 'acc-sync-test',
            'account_code' => 'code',
            'active' => true,
            'frozen' => false,
            'account_balance' => '1',
            'available_balance' => '1',
        ]);

        $addr = '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        CryptoDepositAddress::query()->create([
            'virtual_account_id' => $va->id,
            'user_wallet_id' => null,
            'blockchain' => 'ethereum',
            'currency' => 'eth',
            'address' => $addr,
            'index' => 0,
            'private_key_encrypted' => null,
        ]);

        $tx = Transaction::query()->create([
            'user_id' => $user->id,
            'transaction_id' => Transaction::generateTransactionId(),
            'type' => 'crypto_deposit',
            'category' => 'on_chain_receive',
            'status' => 'completed',
            'currency' => 'ETH',
            'amount' => '1',
            'fee' => 0,
            'total_amount' => '1',
            'reference' => Transaction::generateTransactionId(),
            'description' => 'Legacy deposit',
            'metadata' => [
                'blockchain' => 'ethereum',
                'network' => 'ethereum',
                'tx_hash' => '0xlegacyhash1111111111111111111111111111111111111111111111111111',
                'from_address' => '0xbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
                'to_address' => $addr,
                'virtual_account_id' => $va->id,
                'subscription_type' => 'INCOMING_NATIVE_TX',
            ],
            'completed_at' => now(),
        ]);

        $this->assertEquals(0, ReceivedAsset::query()->count());

        $this->getJson('/api/crypto/sync-received-asset?transaction_id='.$tx->id)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('transaction_id', $tx->id);

        $this->assertEquals(1, ReceivedAsset::query()->count());
        $this->assertDatabaseHas('received_assets', [
            'transaction_id' => $tx->id,
            'tx_hash' => '0xlegacyhash1111111111111111111111111111111111111111111111111111',
        ]);

        $this->getJson('/api/crypto/sync-received-asset?transaction_id='.$tx->id)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Already synced; no changes.');
    }
}
