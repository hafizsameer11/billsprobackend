<?php

namespace Tests\Feature;

use App\Models\CryptoExchangeRate;
use App\Models\WalletCurrency;
use App\Services\Crypto\CryptoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExchangeRateBlockchainTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_blockchain_when_same_currency_on_two_chains(): void
    {
        foreach ([
            ['ethereum', 2.5],
            ['bsc', 2.7],
        ] as [$chain, $rate]) {
            WalletCurrency::query()->create([
                'blockchain' => $chain,
                'currency' => 'DEMOT',
                'symbol' => 'DEM',
                'name' => 'Demo Token',
                'icon' => null,
                'price' => null,
                'naira_price' => null,
                'rate' => $rate,
                'token_type' => 'ERC-20',
                'contract_address' => '0x'.str_repeat('1', 40),
                'decimals' => 18,
                'is_token' => true,
                'blockchain_name' => $chain,
                'is_active' => true,
            ]);
        }

        $crypto = app(CryptoService::class);
        $noChain = $crypto->getExchangeRate('NGN', 'DEMOT', 1000, null);
        $this->assertFalse($noChain['success']);

        $eth = $crypto->getExchangeRate('NGN', 'DEMOT', 1000, 'ethereum');
        $this->assertTrue($eth['success']);
        $this->assertSame('ethereum', $eth['data']['blockchain']);
        $this->assertEquals(2.5, $eth['data']['rate']);

        $bsc = $crypto->getExchangeRate('NGN', 'DEMOT', 1000, 'bsc');
        $this->assertTrue($bsc['success']);
        $this->assertSame('bsc', $bsc['data']['blockchain']);
        $this->assertEquals(2.7, $bsc['data']['rate']);
    }

    public function test_buy_uses_rate_buy_and_sell_uses_rate_sell(): void
    {
        $wc = WalletCurrency::query()->create([
            'blockchain' => 'polygon',
            'currency' => 'DEM2',
            'symbol' => 'DM2',
            'name' => 'Demo 2',
            'icon' => null,
            'price' => null,
            'naira_price' => null,
            'rate' => 2.0,
            'token_type' => null,
            'contract_address' => null,
            'decimals' => 18,
            'is_token' => false,
            'blockchain_name' => 'polygon',
            'is_active' => true,
        ]);

        CryptoExchangeRate::query()->create([
            'wallet_currency_id' => $wc->id,
            'rate_buy' => 3.0,
            'rate_sell' => 2.5,
        ]);

        $crypto = app(CryptoService::class);
        $buy = $crypto->getExchangeRate('NGN', 'DEM2', 1000, 'polygon');
        $this->assertTrue($buy['success']);
        $this->assertEquals(3.0, $buy['data']['rate']);

        $sell = $crypto->getExchangeRate('DEM2', 'NGN', 2, 'polygon');
        $this->assertTrue($sell['success']);
        $this->assertEquals(2.5, $sell['data']['rate']);
    }
}
