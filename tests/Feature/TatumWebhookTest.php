<?php

namespace Tests\Feature;

use App\Jobs\ProcessTatumWebhookJob;
use App\Models\CryptoDepositAddress;
use App\Models\MasterWallet;
use App\Models\ReceivedAsset;
use App\Models\TatumRawWebhook;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VirtualAccount;
use App\Models\WalletCurrency;
use App\Models\WebhookRawPayload;
use App\Services\Tatum\DepositAddressService;
use App\Services\Tatum\TatumWebhookProcessingOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
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

        $this->assertDatabaseHas('received_assets', [
            'user_id' => $user->id,
            'virtual_account_id' => $va->id,
            'transaction_id' => $tx->id,
            'tx_hash' => '0xtxhash1234567890123456789012345678901234567890123456789012345678',
        ]);
        $this->assertEquals(1, ReceivedAsset::query()->count());

        // Idempotent second delivery
        $this->postJson('/api/webhooks/tatum', $payload)->assertOk();
        $this->assertEquals(1, Transaction::query()->where('type', 'crypto_deposit')->count());
        $this->assertEquals(1, ReceivedAsset::query()->count());

        $this->assertEquals(2, TatumRawWebhook::query()->count());
    }

    public function test_tatum_webhook_ignores_when_address_is_master_wallet(): void
    {
        $hotAddr = '0xdddddddddddddddddddddddddddddddddddddddd';

        MasterWallet::query()->create([
            'blockchain' => 'ethereum',
            'address' => $hotAddr,
            'label' => 'test',
        ]);

        $payload = [
            'subscriptionType' => 'INCOMING_NATIVE_TX',
            'txId' => '0xuniquehash1111111111111111111111111111111111111111111111111111',
            'address' => $hotAddr,
            'counterAddress' => '0xeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee',
            'amount' => '1',
        ];

        $this->postJson('/api/webhooks/tatum', $payload)->assertOk();

        $this->assertEquals(0, Transaction::query()->where('type', 'crypto_deposit')->count());
    }

    public function test_tatum_webhook_resolves_counter_addresses_array(): void
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

        $ourAddress = '0xffffffffffffffffffffffffffffffffffffffff';

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
            'txId' => '0xuniquehash2222222222222222222222222222222222222222222222222222',
            'address' => $ourAddress,
            'counterAddresses' => ['0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'],
            'amount' => '0.25',
        ];

        $this->postJson('/api/webhooks/tatum', $payload)->assertOk();

        $va->refresh();
        $this->assertEqualsWithDelta(0.25, (float) $va->available_balance, 0.0001);
    }

    public function test_tatum_v4_wrapped_native_webhook_credits_by_to_address(): void
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
            'account_id' => 'legacy-va-id-should-not-matter',
            'account_code' => 'code',
            'active' => true,
            'frozen' => false,
            'account_balance' => '0',
            'available_balance' => '0',
        ]);

        $ourAddress = '0x1234567890123456789012345678901234567890';

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
            'data' => [
                'kind' => 'transfer',
                'subscriptionType' => 'INCOMING_NATIVE_TX',
                'txId' => '0xv4nativehash111111111111111111111111111111111111111111111111',
                'from' => '0xbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
                'to' => $ourAddress,
                'value' => '500000000000000000',
                'currency' => 'ETH',
                'chain' => 'ethereum-mainnet',
                'tokenMetadata' => [
                    'type' => 'native',
                    'decimals' => 18,
                    'symbol' => 'ETH',
                ],
            ],
        ];

        $this->postJson('/api/webhooks/tatum', $payload)->assertOk();

        $va->refresh();
        $this->assertEqualsWithDelta(0.5, (float) $va->available_balance, 0.0001);
    }

    public function test_legacy_account_id_field_is_ignored_without_deposit_address(): void
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

        VirtualAccount::query()->create([
            'user_id' => $user->id,
            'currency_id' => $wc->id,
            'blockchain' => 'ethereum',
            'currency' => 'ETH',
            'customer_id' => (string) $user->id,
            'account_id' => 'tatum-ledger-account-id',
            'account_code' => 'code',
            'active' => true,
            'frozen' => false,
            'account_balance' => '0',
            'available_balance' => '0',
        ]);

        $this->postJson('/api/webhooks/tatum', [
            'subscriptionType' => 'INCOMING_NATIVE_TX',
            'accountId' => 'tatum-ledger-account-id',
            'txId' => '0xaccountidonlyhash1111111111111111111111111111111111111111',
            'to' => '0xdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef',
            'from' => '0xcccccccccccccccccccccccccccccccccccccccc',
            'amount' => '1',
        ])->assertOk();

        $this->assertEquals(0, Transaction::query()->where('type', 'crypto_deposit')->count());
    }

    public function test_tatum_webhook_incoming_fungible_uses_token_id_and_metadata(): void
    {
        $user = User::factory()->create();

        $wc = WalletCurrency::query()->create([
            'blockchain' => 'ethereum',
            'currency' => 'USDT',
            'symbol' => 'USDT',
            'name' => 'Tether USD',
            'price' => 1,
            'rate' => 1,
            'decimals' => 6,
            'is_token' => true,
            'is_active' => true,
            'contract_address' => '0xdAC17F958D2ee523a2206206994597C13D831ec7',
        ]);

        $va = VirtualAccount::query()->create([
            'user_id' => $user->id,
            'currency_id' => $wc->id,
            'blockchain' => 'ethereum',
            'currency' => 'USDT',
            'customer_id' => (string) $user->id,
            'account_id' => 'acc-test-'.uniqid(),
            'account_code' => 'code',
            'active' => true,
            'frozen' => false,
            'account_balance' => '0',
            'available_balance' => '0',
        ]);

        $ourAddress = '0x2dfeb64ff05a1561ede5f7e636bba19e9a4270ee';

        CryptoDepositAddress::query()->create([
            'virtual_account_id' => $va->id,
            'user_wallet_id' => null,
            'blockchain' => 'ethereum',
            'currency' => 'usdt',
            'address' => $ourAddress,
            'index' => 0,
            'private_key_encrypted' => null,
        ]);

        $payload = [
            'kind' => 'token_transfer',
            'subscriptionType' => 'INCOMING_FUNGIBLE_TX',
            'txId' => '0x835188684f9d48779e67bc843efe2042ef0e474e6a1fdfda6db0bfd290ac8ba5',
            'to' => $ourAddress,
            'from' => '0xc995f6d188ff469d736f1ef23cf3b48c515ee2ba',
            'value' => '5',
            'contractAddress' => '0xdac17f958d2ee523a2206206994597c13d831ec7',
            'currency' => 'ETH',
            'tokenId' => '5000000',
            'tokenMetadata' => [
                'type' => 'fungible',
                'symbol' => 'USDT',
                'name' => 'Tether USD',
                'decimals' => 6,
            ],
            'chain' => 'ethereum-mainnet',
        ];

        $this->postJson('/api/webhooks/tatum', $payload)->assertOk();

        $va->refresh();
        $this->assertEqualsWithDelta(5.0, (float) $va->available_balance, 0.000001);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'type' => 'crypto_deposit',
            'currency' => 'USDT',
        ]);

        $tx = Transaction::query()->where('type', 'crypto_deposit')->where('user_id', $user->id)->first();
        $this->assertNotNull($tx);
        $this->assertDatabaseHas('received_assets', [
            'user_id' => $user->id,
            'transaction_id' => $tx->id,
            'currency' => 'USDT',
            'tx_hash' => '0x835188684f9d48779e67bc843efe2042ef0e474e6a1fdfda6db0bfd290ac8ba5',
        ]);
    }

    public function test_scam_erc20_deposit_does_not_credit_balance(): void
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
            'account_id' => 'acc-scam-test',
            'account_code' => 'code',
            'active' => true,
            'frozen' => false,
            'account_balance' => '0',
            'available_balance' => '0',
        ]);

        $ourAddress = '0xcccccccccccccccccccccccccccccccccccccccc';

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
            'subscriptionType' => 'INCOMING_FUNGIBLE_TX',
            'txId' => '0xscamhash1111111111111111111111111111111111111111111111111111',
            'address' => $ourAddress,
            'counterAddress' => '0xdddddddddddddddddddddddddddddddddddddddd',
            'amount' => '1000',
            'contractAddress' => '0xdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef',
            'tokenMetadata' => ['symbol' => 'USDT'],
        ];

        $this->postJson('/api/webhooks/tatum', $payload)->assertOk();

        $va->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $va->available_balance, 0.0001);
        $this->assertDatabaseMissing('transactions', [
            'user_id' => $user->id,
            'type' => 'crypto_deposit',
        ]);
        $this->assertDatabaseHas('tatum_raw_webhooks', [
            'outcome' => TatumWebhookProcessingOutcome::IGNORED_UNLISTED_ASSET,
            'processed' => true,
        ]);
    }

    public function test_fake_usdt_with_wrong_contract_and_currency_hint_does_not_credit(): void
    {
        $user = User::factory()->create();

        WalletCurrency::query()->create([
            'blockchain' => 'ethereum',
            'currency' => 'USDT',
            'symbol' => 'USDT',
            'name' => 'Tether USD',
            'price' => 1,
            'rate' => 1,
            'decimals' => 6,
            'is_token' => true,
            'is_active' => true,
            'contract_address' => '0xdac17f958d2ee523a2206206994597c13d831ec7',
        ]);

        $va = VirtualAccount::query()->create([
            'user_id' => $user->id,
            'currency_id' => 1,
            'blockchain' => 'ethereum',
            'currency' => 'USDT',
            'customer_id' => (string) $user->id,
            'account_id' => 'acc-fake-usdt-contract',
            'account_code' => 'code',
            'active' => true,
            'frozen' => false,
            'account_balance' => '0',
            'available_balance' => '0',
        ]);

        $ourAddress = '0xeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';

        CryptoDepositAddress::query()->create([
            'virtual_account_id' => $va->id,
            'blockchain' => 'ethereum',
            'currency' => 'usdt',
            'address' => $ourAddress,
            'index' => 0,
        ]);

        $this->postJson('/api/webhooks/tatum', [
            'subscriptionType' => 'INCOMING_FUNGIBLE_TX',
            'txId' => '0xfakeusdtcontract111111111111111111111111111111111111111111',
            'address' => $ourAddress,
            'counterAddress' => '0xdddddddddddddddddddddddddddddddddddddddd',
            'amount' => '1000000',
            'currency' => 'USDT',
            'contractAddress' => '0xdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef',
            'tokenMetadata' => ['symbol' => 'USDT', 'name' => 'Tether USD', 'decimals' => 6],
        ])->assertOk();

        $va->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $va->available_balance, 0.0001);
        $this->assertDatabaseHas('tatum_raw_webhooks', [
            'outcome' => TatumWebhookProcessingOutcome::IGNORED_UNLISTED_ASSET,
        ]);
    }

    public function test_fake_usdt_v4_without_contract_does_not_credit(): void
    {
        $user = User::factory()->create();

        WalletCurrency::query()->create([
            'blockchain' => 'ethereum',
            'currency' => 'USDT',
            'symbol' => 'USDT',
            'name' => 'Tether USD',
            'price' => 1,
            'rate' => 1,
            'decimals' => 6,
            'is_token' => true,
            'is_active' => true,
            'contract_address' => '0xdac17f958d2ee523a2206206994597c13d831ec7',
        ]);

        $va = VirtualAccount::query()->create([
            'user_id' => $user->id,
            'currency_id' => 1,
            'blockchain' => 'ethereum',
            'currency' => 'USDT',
            'customer_id' => (string) $user->id,
            'account_id' => 'acc-fake-usdt-nocontract',
            'account_code' => 'code',
            'active' => true,
            'frozen' => false,
            'account_balance' => '0',
            'available_balance' => '0',
        ]);

        $ourAddress = '0xffffffffffffffffffffffffffffffffffffffff';

        CryptoDepositAddress::query()->create([
            'virtual_account_id' => $va->id,
            'blockchain' => 'ethereum',
            'currency' => 'usdt',
            'address' => $ourAddress,
            'index' => 0,
        ]);

        $this->postJson('/api/webhooks/tatum', [
            'subscriptionType' => 'INCOMING_FUNGIBLE_TX',
            'txId' => '0xfakeusdtnocontract111111111111111111111111111111111111111',
            'address' => $ourAddress,
            'counterAddress' => '0xdddddddddddddddddddddddddddddddddddddddd',
            'amount' => '500000',
            'currency' => 'USDT',
            'tokenMetadata' => ['symbol' => 'USDT', 'name' => 'Tether USD', 'type' => 'fungible', 'decimals' => 6],
        ])->assertOk();

        $va->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $va->available_balance, 0.0001);
        $this->assertDatabaseHas('tatum_raw_webhooks', [
            'outcome' => TatumWebhookProcessingOutcome::IGNORED_UNLISTED_ASSET,
        ]);
    }

    public function test_address_event_fake_usdt_asset_does_not_credit(): void
    {
        $user = User::factory()->create();

        WalletCurrency::query()->create([
            'blockchain' => 'ethereum',
            'currency' => 'USDT',
            'symbol' => 'USDT',
            'name' => 'Tether USD',
            'price' => 1,
            'rate' => 1,
            'decimals' => 6,
            'is_token' => true,
            'is_active' => true,
            'contract_address' => '0xdac17f958d2ee523a2206206994597c13d831ec7',
        ]);

        $va = VirtualAccount::query()->create([
            'user_id' => $user->id,
            'currency_id' => 1,
            'blockchain' => 'ethereum',
            'currency' => 'USDT',
            'customer_id' => (string) $user->id,
            'account_id' => 'acc-fake-address-event',
            'account_code' => 'code',
            'active' => true,
            'frozen' => false,
            'account_balance' => '0',
            'available_balance' => '0',
        ]);

        $ourAddress = '0xabababababababababababababababababababab';

        CryptoDepositAddress::query()->create([
            'virtual_account_id' => $va->id,
            'blockchain' => 'ethereum',
            'currency' => 'usdt',
            'address' => $ourAddress,
            'index' => 0,
        ]);

        $this->postJson('/api/webhooks/tatum', [
            'subscriptionType' => 'ADDRESS_EVENT',
            'type' => 'token',
            'txId' => '0xfakeaddressevent111111111111111111111111111111111111111',
            'address' => $ourAddress,
            'counterAddress' => '0xdddddddddddddddddddddddddddddddddddddddd',
            'amount' => '99999',
            'asset' => '0xdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef',
            'chain' => 'ethereum-mainnet',
        ])->assertOk();

        $va->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $va->available_balance, 0.0001);
        $this->assertDatabaseHas('tatum_raw_webhooks', [
            'outcome' => TatumWebhookProcessingOutcome::IGNORED_UNLISTED_ASSET,
        ]);
    }

    public function test_account_incoming_btc_without_sender_credits_deposit(): void
    {
        $user = User::factory()->create();
        $btcAddr = 'bc1q082qd35scjfvgt2gvwxces583wvwndw272t7q6';

        $wc = WalletCurrency::query()->create([
            'blockchain' => 'bitcoin',
            'currency' => 'BTC',
            'symbol' => 'BTC',
            'name' => 'Bitcoin',
            'price' => 50000,
            'rate' => 50000,
            'decimals' => 8,
            'is_token' => false,
            'is_active' => true,
        ]);

        $va = VirtualAccount::query()->create([
            'user_id' => $user->id,
            'currency_id' => $wc->id,
            'blockchain' => 'bitcoin',
            'currency' => 'BTC',
            'customer_id' => (string) $user->id,
            'account_id' => 'acc-btc-'.uniqid(),
            'account_code' => 'code',
            'active' => true,
            'frozen' => false,
            'account_balance' => '0',
            'available_balance' => '0',
        ]);

        CryptoDepositAddress::query()->create([
            'virtual_account_id' => $va->id,
            'blockchain' => 'bitcoin',
            'currency' => 'btc',
            'address' => $btcAddr,
            'index' => 0,
        ]);

        $this->postJson('/api/webhooks/tatum', [
            'to' => $btcAddr,
            'from' => null,
            'txId' => '6fbec07b752d38cb95e5f5b536e1ec56bb99aae63e4eaab43e0e63bb60622a72',
            'amount' => '0.00069925',
            'currency' => 'BTC',
            'accountId' => '6896004e17e9f1bce5357838',
            'subscriptionType' => 'ACCOUNT_INCOMING_BLOCKCHAIN_TRANSACTION',
            'blockHeight' => 944439,
            'date' => 1775808443807,
        ])->assertOk();

        $va->refresh();
        $this->assertGreaterThan(0, (float) $va->available_balance);
    }

    public function test_account_incoming_usdt_tron_credits_by_to_address(): void
    {
        $user = User::factory()->create();
        $tronAddr = 'TUU8wTyj9KWur3UfuDaJRm7DvSEDCPxQDR';

        $wc = WalletCurrency::query()->create([
            'blockchain' => 'tron',
            'currency' => 'USDT_TRON',
            'symbol' => 'USDT',
            'name' => 'Tether USD (TRON)',
            'price' => 1,
            'rate' => 1,
            'decimals' => 6,
            'is_token' => true,
            'is_active' => true,
            'contract_address' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
        ]);

        $va = VirtualAccount::query()->create([
            'user_id' => $user->id,
            'currency_id' => $wc->id,
            'blockchain' => 'tron',
            'currency' => 'USDT_TRON',
            'customer_id' => (string) $user->id,
            'account_id' => 'acc-tron-'.uniqid(),
            'account_code' => 'code',
            'active' => true,
            'frozen' => false,
            'account_balance' => '0',
            'available_balance' => '0',
        ]);

        CryptoDepositAddress::query()->create([
            'virtual_account_id' => $va->id,
            'blockchain' => 'tron',
            'currency' => 'usdt_tron',
            'address' => $tronAddr,
            'index' => 0,
        ]);

        $this->postJson('/api/webhooks/tatum', [
            'to' => $tronAddr,
            'from' => 'THNYgDYHAAtkddurnWtbwhUurMUf5GJvz3',
            'txId' => '6c704625a1acfd0283607500498bf5393bbf16390fd9a8bfe1bf121eee09be93',
            'amount' => '220',
            'currency' => 'USDT_TRON',
            'accountId' => '68065ea14c2c2bba9c92e44e',
            'subscriptionType' => 'ACCOUNT_INCOMING_BLOCKCHAIN_TRANSACTION',
            'blockHeight' => 81716924,
            'date' => 1775818397508,
        ])->assertOk();

        $va->refresh();
        $this->assertGreaterThan(0, (float) $va->available_balance);
        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'type' => 'crypto_deposit',
            'currency' => 'USDT_TRON',
        ]);
    }

    public function test_address_event_token_with_asset_contract_credits_usdt(): void
    {
        $user = User::factory()->create();
        $ourAddress = '0x5dc109ced8e1c88d65a8f6941a04b9be81a49791';

        $wc = WalletCurrency::query()->create([
            'blockchain' => 'ethereum',
            'currency' => 'USDT',
            'symbol' => 'USDT',
            'name' => 'Tether USD',
            'price' => 1,
            'rate' => 1,
            'decimals' => 6,
            'is_token' => true,
            'is_active' => true,
            'contract_address' => '0xdac17f958d2ee523a2206206994597c13d831ec7',
        ]);

        $va = VirtualAccount::query()->create([
            'user_id' => $user->id,
            'currency_id' => $wc->id,
            'blockchain' => 'ethereum',
            'currency' => 'USDT',
            'customer_id' => (string) $user->id,
            'account_id' => 'acc-usdt-'.uniqid(),
            'account_code' => 'code',
            'active' => true,
            'frozen' => false,
            'account_balance' => '0',
            'available_balance' => '0',
        ]);

        CryptoDepositAddress::query()->create([
            'virtual_account_id' => $va->id,
            'blockchain' => 'ethereum',
            'currency' => 'usdt',
            'address' => $ourAddress,
            'index' => 0,
        ]);

        $this->postJson('/api/webhooks/tatum', [
            'address' => $ourAddress,
            'amount' => '3',
            'asset' => '0xdac17f958d2ee523a2206206994597c13d831ec7',
            'counterAddress' => '0x5d4675b0556dbcc1571a79c29a671b28865eea48',
            'txId' => '0x50591a32f314cf1c145add29a8c93a53984e4d37a552865123d3618fac46b8ed',
            'type' => 'token',
            'chain' => 'ethereum-mainnet',
            'subscriptionType' => 'ADDRESS_EVENT',
        ])->assertOk();

        $va->refresh();
        $this->assertGreaterThan(0, (float) $va->available_balance);
    }

    public function test_address_event_fee_is_ignored(): void
    {
        $user = User::factory()->create();
        $ourAddress = '0x5dc109ced8e1c88d65a8f6941a04b9be81a49791';

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
            'account_id' => 'acc-fee-'.uniqid(),
            'account_code' => 'code',
            'active' => true,
            'frozen' => false,
            'account_balance' => '0',
            'available_balance' => '0',
        ]);

        CryptoDepositAddress::query()->create([
            'virtual_account_id' => $va->id,
            'blockchain' => 'ethereum',
            'currency' => 'eth',
            'address' => $ourAddress,
            'index' => 0,
        ]);

        $this->postJson('/api/webhooks/tatum', [
            'address' => $ourAddress,
            'amount' => '-0.00000123891',
            'asset' => 'ETH',
            'txId' => '0x7c24a8f802dfcdea81d54648b3d0ba160fa17c26b50512449c40d61d6d1c364a',
            'type' => 'fee',
            'chain' => 'ethereum-mainnet',
            'subscriptionType' => 'ADDRESS_EVENT',
        ])->assertOk();

        $va->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $va->available_balance, 0.0001);
    }

    public function test_incoming_fungible_human_amount_with_contract_credits_usdt(): void
    {
        $user = User::factory()->create();
        $ourAddress = '0x5dc109ced8e1c88d65a8f6941a04b9be81a49791';

        $wc = WalletCurrency::query()->create([
            'blockchain' => 'ethereum',
            'currency' => 'USDT',
            'symbol' => 'USDT',
            'name' => 'Tether USD',
            'price' => 1,
            'rate' => 1,
            'decimals' => 6,
            'is_token' => true,
            'is_active' => true,
            'contract_address' => '0xdac17f958d2ee523a2206206994597c13d831ec7',
        ]);

        $va = VirtualAccount::query()->create([
            'user_id' => $user->id,
            'currency_id' => $wc->id,
            'blockchain' => 'ethereum',
            'currency' => 'USDT',
            'customer_id' => (string) $user->id,
            'account_id' => 'acc-if-'.uniqid(),
            'account_code' => 'code',
            'active' => true,
            'frozen' => false,
            'account_balance' => '0',
            'available_balance' => '0',
        ]);

        CryptoDepositAddress::query()->create([
            'virtual_account_id' => $va->id,
            'blockchain' => 'ethereum',
            'currency' => 'usdt',
            'address' => $ourAddress,
            'index' => 0,
        ]);

        $this->postJson('/api/webhooks/tatum', [
            'currency' => 'ETH',
            'chain' => 'ethereum-mainnet',
            'amount' => '3',
            'address' => $ourAddress,
            'counterAddress' => '0x5d4675b0556dbcc1571a79c29a671b28865eea48',
            'subscriptionType' => 'INCOMING_FUNGIBLE_TX',
            'txId' => '0xa723719520bfcd5092445f9cfcc1b5ece84d81b53c0282faa882508b853b2009',
            'contractAddress' => '0xdac17f958d2ee523a2206206994597c13d831ec7',
        ])->assertOk();

        $va->refresh();
        $this->assertEqualsWithDelta(3.0, (float) $va->available_balance, 0.01);
    }

    public function test_public_tatum_replay_routes_are_not_available(): void
    {
        $this->getJson('/api/webhooks/tatum/replay/999999')->assertNotFound();
        $this->getJson('/api/webhooks/tatum/replay-pending')->assertNotFound();
    }

    public function test_admin_tatum_replay_queues_job_when_enabled(): void
    {
        Queue::fake();
        config(['admin.webhook_replay_enabled' => true]);

        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin);

        $raw = TatumRawWebhook::query()->create([
            'raw_data' => '{"txId":"0xadminreplaytest"}',
            'processed' => false,
        ]);

        $this->postJson('/api/admin/webhooks/tatum/raw/'.$raw->id.'/replay')
            ->assertOk()
            ->assertJsonPath('data.tatum_raw_webhook_id', $raw->id);

        Queue::assertPushed(ProcessTatumWebhookJob::class, 1);
    }

    public function test_tatum_webhook_records_webhook_raw_payloads_and_outcome(): void
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
            'account_id' => 'acc-audit-'.uniqid(),
            'account_code' => 'code',
            'active' => true,
            'frozen' => false,
            'account_balance' => '0',
            'available_balance' => '0',
        ]);

        $ourAddress = '0x1111111111111111111111111111111111111111';
        $txId = '0xauditaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

        CryptoDepositAddress::query()->create([
            'virtual_account_id' => $va->id,
            'blockchain' => 'ethereum',
            'currency' => 'eth',
            'address' => $ourAddress,
            'index' => 0,
        ]);

        $this->postJson('/api/webhooks/tatum', [
            'subscriptionType' => 'INCOMING_NATIVE_TX',
            'txId' => $txId,
            'address' => $ourAddress,
            'counterAddress' => '0x2222222222222222222222222222222222222222',
            'amount' => '0.1',
        ])->assertOk();

        $this->assertDatabaseHas('webhook_raw_payloads', [
            'provider' => 'tatum',
            'tx_id' => $txId,
            'receiving_address' => $ourAddress,
        ]);

        $this->assertDatabaseHas('tatum_raw_webhooks', [
            'tx_id' => $txId,
            'outcome' => TatumWebhookProcessingOutcome::CREDITED,
            'processed' => true,
        ]);

        $audit = WebhookRawPayload::query()->where('tx_id', $txId)->first();
        $this->assertNotNull($audit);
        $this->assertSame('tatum', $audit->provider);
        $this->assertNotNull($audit->tatum_raw_webhook_id);
    }

    public function test_tron_deposit_address_lookup_is_case_sensitive(): void
    {
        $user = User::factory()->create();
        $tronAddr = 'TUU8wTyj9KWur3UfuDaJRm7DvSEDCPxQDR';

        $wc = WalletCurrency::query()->create([
            'blockchain' => 'tron',
            'currency' => 'TRX',
            'symbol' => 'TRX',
            'name' => 'TRON',
            'price' => 0.1,
            'rate' => 0.1,
            'decimals' => 6,
            'is_token' => false,
            'is_active' => true,
        ]);

        $va = VirtualAccount::query()->create([
            'user_id' => $user->id,
            'currency_id' => $wc->id,
            'blockchain' => 'tron',
            'currency' => 'TRX',
            'customer_id' => (string) $user->id,
            'account_id' => 'acc-tron-case-'.uniqid(),
            'account_code' => 'code',
            'active' => true,
            'frozen' => false,
            'account_balance' => '0',
            'available_balance' => '0',
        ]);

        CryptoDepositAddress::query()->create([
            'virtual_account_id' => $va->id,
            'blockchain' => 'tron',
            'currency' => 'trx',
            'address' => $tronAddr,
            'index' => 0,
        ]);

        $found = DepositAddressService::findByIncomingAddress($tronAddr);
        $this->assertNotNull($found);
        $this->assertSame($tronAddr, $found->address);

        $this->assertNull(DepositAddressService::findByIncomingAddress(strtolower($tronAddr)));

        $this->postJson('/api/webhooks/tatum', [
            'subscriptionType' => 'INCOMING_NATIVE_TX',
            'txId' => 'troncaseaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'address' => $tronAddr,
            'counterAddress' => 'THNYgDYHAAtkddurnWtbwhUurMUf5GJvz3',
            'amount' => '10',
        ])->assertOk();

        $va->refresh();
        $this->assertGreaterThan(0, (float) $va->available_balance);
        $this->assertDatabaseHas('tatum_raw_webhooks', [
            'outcome' => TatumWebhookProcessingOutcome::CREDITED,
        ]);
    }

    public function test_master_wallet_webhook_sets_ignored_master_outcome(): void
    {
        $hotAddr = '0xdddddddddddddddddddddddddddddddddddddddd';

        MasterWallet::query()->create([
            'blockchain' => 'ethereum',
            'address' => $hotAddr,
            'label' => 'test',
        ]);

        $this->postJson('/api/webhooks/tatum', [
            'subscriptionType' => 'INCOMING_NATIVE_TX',
            'txId' => '0xmasteroutcome111111111111111111111111111111111111111111111',
            'address' => $hotAddr,
            'counterAddress' => '0xeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee',
            'amount' => '1',
        ])->assertOk();

        $this->assertDatabaseHas('tatum_raw_webhooks', [
            'outcome' => TatumWebhookProcessingOutcome::IGNORED_MASTER_WALLET,
            'processed' => true,
        ]);
    }
}
