<?php

namespace Tests\Feature;

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
}
