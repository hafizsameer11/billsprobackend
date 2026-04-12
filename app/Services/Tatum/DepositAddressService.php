<?php

namespace App\Services\Tatum;

use App\Models\CryptoDepositAddress;
use App\Models\VirtualAccount;
use App\Models\WalletCurrency;
use App\Services\Crypto\KeyEncryptionService;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class DepositAddressService
{
    /**
     * @var array<string, string>
     */
    protected const BLOCKCHAIN_NORMALIZATION = [
        'ethereum' => 'ethereum',
        'eth' => 'ethereum',
        'ethereum-mainnet' => 'ethereum',
        'eth-mainnet' => 'ethereum',
        'erc20' => 'ethereum',
        'tron' => 'tron',
        'trx' => 'tron',
        'bsc' => 'bsc',
        'binance' => 'bsc',
        'binancesmartchain' => 'bsc',
        'polygon' => 'polygon',
        'matic' => 'polygon',
        'bitcoin' => 'bitcoin',
        'btc' => 'bitcoin',
        'litecoin' => 'litecoin',
        'ltc' => 'litecoin',
        'solana' => 'solana',
        'sol' => 'solana',
        'dogecoin' => 'dogecoin',
        'doge' => 'dogecoin',
        'xrp' => 'xrp',
        'ripple' => 'xrp',
    ];

    public function __construct(
        protected UserWalletService $userWalletService,
        protected KeyEncryptionService $encryption
    ) {}

    public static function normalizeBlockchain(string $blockchain): string
    {
        $b = strtolower(trim($blockchain));

        return self::BLOCKCHAIN_NORMALIZATION[$b] ?? $b;
    }

    /**
     * Return persisted deposit address for this virtual account, creating Tatum address + webhooks on first use.
     */
    public function ensureDepositAddressForVirtualAccount(VirtualAccount $virtualAccount): string
    {
        $existing = CryptoDepositAddress::query()
            ->where('virtual_account_id', $virtualAccount->id)
            ->orderByDesc('id')
            ->first();

        if ($existing) {
            return $existing->address;
        }

        return $this->generateAndAssignToVirtualAccount($virtualAccount->id)->address;
    }

    public function generateAndAssignToVirtualAccount(int $virtualAccountId): CryptoDepositAddress
    {
        if (config('tatum.use_mock')) {
            throw new RuntimeException('generateAndAssignToVirtualAccount requires Tatum (mock mode is on).');
        }

        $virtualAccount = VirtualAccount::query()
            ->with('walletCurrency')
            ->whereKey($virtualAccountId)
            ->firstOrFail();

        $blockchain = strtolower($virtualAccount->blockchain);
        $currency = strtolower($virtualAccount->currency);
        $normalizedBlockchain = self::normalizeBlockchain($blockchain);
        $userId = $virtualAccount->user_id;

        $existingAddress = $this->findExistingAddressForUserChain($userId, $normalizedBlockchain);

        if ($existingAddress) {
            $duplicate = CryptoDepositAddress::query()
                ->where('virtual_account_id', $virtualAccountId)
                ->where('address', $existingAddress->address)
                ->first();

            if ($duplicate) {
                return $duplicate;
            }

            if ($existingAddress->index === null) {
                throw new RuntimeException('Cannot reuse address '.$existingAddress->address.': index missing.');
            }

            return CryptoDepositAddress::create([
                'virtual_account_id' => $virtualAccountId,
                'user_wallet_id' => $existingAddress->user_wallet_id,
                'blockchain' => $blockchain,
                'currency' => $currency,
                'address' => $existingAddress->address,
                'index' => $existingAddress->index,
                'private_key_encrypted' => $existingAddress->private_key_encrypted,
            ]);
        }

        $userWallet = $this->userWalletService->getOrCreateUserWallet($userId, $normalizedBlockchain);
        $tatum = TatumClient::fromConfig();

        $isNoXpub = in_array($normalizedBlockchain, ['solana', 'sol', 'xrp', 'ripple'], true);
        $addressIndex = 0;

        if ($isNoXpub) {
            $secret = $this->userWalletService->decryptMnemonicOrSecret($userWallet);
            $address = $userWallet->xpub;
            if (! $address) {
                throw new RuntimeException('Non-HD wallet missing stored address.');
            }
            $privateKey = $secret;
        } else {
            if (! $userWallet->xpub) {
                throw new RuntimeException('HD wallet missing xpub for '.$normalizedBlockchain);
            }
            $mnemonic = $this->userWalletService->decryptMnemonicOrSecret($userWallet);
            $address = $tatum->generateAddress($normalizedBlockchain, $userWallet->xpub, $addressIndex);
            $privateKey = $tatum->generatePrivateKey($normalizedBlockchain, $mnemonic, $addressIndex);
        }

        $encryptedPk = $this->encryption->encrypt($privateKey);

        $depositAddress = CryptoDepositAddress::create([
            'virtual_account_id' => $virtualAccountId,
            'user_wallet_id' => $userWallet->id,
            'blockchain' => $blockchain,
            'currency' => $currency,
            'address' => $address,
            'index' => $addressIndex,
            'private_key_encrypted' => $encryptedPk,
        ]);

        $this->assertEncryptedPrivateKeyRoundTrips($encryptedPk);

        $this->registerWebhooks($address, $normalizedBlockchain);

        return $depositAddress;
    }

    /**
     * Confirm AES-256-CBC payload stored in DB can be decrypted with ENCRYPTION_KEY.
     */
    protected function assertEncryptedPrivateKeyRoundTrips(string $encryptedPrivateKey): void
    {
        $plain = $this->encryption->decrypt($encryptedPrivateKey);
        if ($plain === '') {
            throw new RuntimeException('Decrypted deposit private key is empty after storage.');
        }
    }

    protected function findExistingAddressForUserChain(int $userId, string $normalizedBlockchain): ?CryptoDepositAddress
    {
        return CryptoDepositAddress::query()
            ->whereHas('virtualAccount', fn ($q) => $q->where('user_id', $userId))
            ->with('virtualAccount')
            ->orderBy('created_at')
            ->get()
            ->first(function (CryptoDepositAddress $da) use ($normalizedBlockchain) {
                return self::normalizeBlockchain((string) $da->blockchain) === $normalizedBlockchain;
            });
    }

    protected function registerWebhooks(string $address, string $baseBlockchain): void
    {
        $webhookUrl = (string) config('tatum.webhook_url');
        if ($webhookUrl === '') {
            Log::warning('Tatum webhook_url empty; skipping subscription registration.');

            return;
        }

        $tatum = TatumClient::fromConfig();

        try {
            $tatum->registerAddressWebhookV4($address, $baseBlockchain, $webhookUrl, 'INCOMING_NATIVE_TX');
        } catch (\Throwable $e) {
            Log::warning('Tatum INCOMING_NATIVE_TX subscription failed: '.$e->getMessage());
        }

        $hasFungible = WalletCurrency::query()
            ->whereRaw('LOWER(blockchain) = ?', [strtolower($baseBlockchain)])
            ->where('is_token', true)
            ->whereNotNull('contract_address')
            ->exists();

        if ($hasFungible) {
            try {
                $tatum->registerAddressWebhookV4($address, $baseBlockchain, $webhookUrl, 'INCOMING_FUNGIBLE_TX');
            } catch (\Throwable $e) {
                Log::warning('Tatum INCOMING_FUNGIBLE_TX subscription failed: '.$e->getMessage());
            }
        }
    }
}
