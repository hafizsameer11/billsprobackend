<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\VirtualAccount;
use App\Models\WalletCurrency;
use App\Services\Crypto\AllowedCryptoDepositResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AllowedCryptoDepositResolverTest extends TestCase
{
    use RefreshDatabase;

    protected AllowedCryptoDepositResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = app(AllowedCryptoDepositResolver::class);
    }

    public function test_allows_real_usdt_when_contract_matches(): void
    {
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

        $match = $this->resolver->resolveAllowedTokenDeposit('ethereum', [
            'contractAddress' => '0xdAC17F958D2ee523a2206206994597C13D831ec7',
            'currency' => 'USDT',
            'tokenMetadata' => ['symbol' => 'USDT'],
        ], 'INCOMING_FUNGIBLE_TX');

        $this->assertNotNull($match);
        $this->assertSame('USDT', $match->currency);
    }

    public function test_rejects_fake_usdt_when_contract_does_not_match_even_if_currency_says_usdt(): void
    {
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

        $match = $this->resolver->resolveAllowedTokenDeposit('ethereum', [
            'contractAddress' => '0xdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef',
            'currency' => 'USDT',
            'tokenMetadata' => ['symbol' => 'USDT', 'name' => 'Tether USD'],
        ], 'INCOMING_FUNGIBLE_TX');

        $this->assertNull($match);
        $this->assertTrue($this->resolver->hasUnlistedContract([
            'asset' => '0xdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef',
        ], 'ethereum'));
    }

    public function test_rejects_v4_fungible_without_contract_even_if_currency_says_usdt(): void
    {
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

        $match = $this->resolver->resolveAllowedTokenDeposit('ethereum', [
            'currency' => 'USDT',
            'tokenMetadata' => ['symbol' => 'USDT', 'type' => 'fungible'],
            'amount' => '1000000',
        ], 'INCOMING_FUNGIBLE_TX');

        $this->assertNull($match);
    }

    public function test_allows_v3_ledger_usdt_tron_by_currency_when_contract_omitted(): void
    {
        WalletCurrency::query()->create([
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

        $match = $this->resolver->resolveAllowedTokenDeposit('tron', [
            'currency' => 'USDT_TRON',
            'amount' => '100',
            'to' => 'TUU8wTyj9KWur3UfuDaJRm7DvSEDCPxQDR',
        ], 'ACCOUNT_INCOMING_BLOCKCHAIN_TRANSACTION');

        $this->assertNotNull($match);
        $this->assertSame('USDT_TRON', $match->currency);
    }

    public function test_native_resolution_does_not_trust_token_symbol_metadata(): void
    {
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

        $user = User::factory()->create();

        $va = VirtualAccount::query()->create([
            'user_id' => $user->id,
            'currency_id' => $wc->id,
            'currency_id' => 1,
            'blockchain' => 'ethereum',
            'currency' => 'ETH',
            'customer_id' => (string) $user->id,
            'account_id' => 'acc-native-test',
            'account_code' => 'code',
            'active' => true,
            'frozen' => false,
            'account_balance' => '0',
            'available_balance' => '0',
        ]);

        $native = $this->resolver->resolveAllowedNative('ethereum', $va);
        $this->assertNotNull($native);
        $this->assertFalse($this->resolver->isFungiblePayload([
            'currency' => 'ETH',
            'tokenMetadata' => ['symbol' => 'USDT', 'type' => 'native'],
            'type' => 'native',
        ], 'INCOMING_NATIVE_TX'));
    }
}
