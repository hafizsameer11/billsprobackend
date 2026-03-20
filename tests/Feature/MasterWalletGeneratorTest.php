<?php

namespace Tests\Feature;

use App\Models\MasterWallet;
use App\Models\MasterWalletSecret;
use App\Services\Crypto\KeyEncryptionService;
use App\Services\Crypto\MasterWalletGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MasterWalletGeneratorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tatum.use_mock' => false]);
        config(['tatum.api_key' => 'test-tatum-key']);
    }

    public function test_generates_master_wallet_and_persists_encrypted_secret(): void
    {
        $priv = '0x'.str_repeat('a', 64);
        Http::fake([
            'https://api.tatum.io/v3/ethereum/wallet' => Http::response([
                'mnemonic' => 'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about',
                'xpub' => 'xpub6G',
                'address' => '0x1234567890123456789012345678901234567890',
                'privateKey' => $priv,
            ], 200),
        ]);

        $wallet = app(MasterWalletGeneratorService::class)->generate('ethereum', false);

        $this->assertSame('ethereum', $wallet->blockchain);
        $this->assertSame('0x1234567890123456789012345678901234567890', $wallet->address);

        $secret = MasterWalletSecret::query()->where('master_wallet_id', $wallet->id)->first();
        $this->assertNotNull($secret);
        $this->assertMatchesRegularExpression('/^[0-9a-f]+:[0-9a-f]+$/', $secret->private_key_encrypted);

        $enc = app(KeyEncryptionService::class);
        $this->assertSame($priv, $wallet->fresh(['secret'])->decryptedPrivateKey($enc));
        $this->assertSame(
            'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about',
            $wallet->fresh(['secret'])->decryptedMnemonic($enc)
        );
    }

    public function test_refuses_generation_when_tatum_mock_enabled(): void
    {
        config(['tatum.use_mock' => true]);

        $this->expectExceptionMessage('TATUM_USE_MOCK');

        app(MasterWalletGeneratorService::class)->generate('ethereum', false);
    }

    public function test_force_replaces_existing_master_wallet(): void
    {
        $firstAddr = '0x1111111111111111111111111111111111111111';
        $secondAddr = '0x2222222222222222222222222222222222222222';
        $priv = '0x'.str_repeat('b', 64);

        Http::fake([
            'https://api.tatum.io/v3/ethereum/wallet' => Http::sequence()
                ->push([
                    'mnemonic' => 'zoo zoo zoo zoo zoo zoo zoo zoo zoo zoo zoo wrong',
                    'xpub' => 'xpub1',
                    'address' => $firstAddr,
                    'privateKey' => '0x'.str_repeat('c', 64),
                ], 200)
                ->push([
                    'mnemonic' => 'legal winner thank year wave sausage worth useful legal winner thank yellow',
                    'xpub' => 'xpub2',
                    'address' => $secondAddr,
                    'privateKey' => $priv,
                ], 200),
        ]);

        app(MasterWalletGeneratorService::class)->generate('ethereum', false);
        $this->assertSame(1, MasterWallet::query()->where('blockchain', 'ethereum')->count());

        $w = app(MasterWalletGeneratorService::class)->generate('ethereum', true);
        $this->assertSame($secondAddr, $w->address);
        $this->assertSame(1, MasterWallet::query()->where('blockchain', 'ethereum')->count());
        $this->assertSame($priv, $w->fresh(['secret'])->decryptedPrivateKey(app(KeyEncryptionService::class)));
    }
}
