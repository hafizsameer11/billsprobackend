<?php

namespace App\Services\Tatum;

use App\Models\UserWallet;
use App\Services\Crypto\KeyEncryptionService;
use RuntimeException;

class UserWalletService
{
    public function __construct(
        protected KeyEncryptionService $encryption
    ) {}

    /**
     * Per-user wallet for a base blockchain (HD mnemonic + xpub, or single keypair for Solana/XRP).
     */
    public function getOrCreateUserWallet(int $userId, string $baseBlockchain): UserWallet
    {
        $normalized = strtolower(trim($baseBlockchain));

        $existing = UserWallet::query()
            ->where('user_id', $userId)
            ->where('blockchain', $normalized)
            ->first();

        if ($existing) {
            return $existing;
        }

        if (config('tatum.use_mock')) {
            throw new RuntimeException('Cannot create user wallet while TATUM_USE_MOCK is true.');
        }

        $tatum = TatumClient::fromConfig();
        $data = $tatum->createWallet($normalized);

        $isNoXpub = in_array($normalized, ['solana', 'sol', 'xrp', 'ripple'], true);

        if ($isNoXpub) {
            $address = $data['address'] ?? '';
            $pk = $data['privateKey'] ?? $data['secret'] ?? '';
            if ($address === '' || $pk === '') {
                throw new RuntimeException('Tatum wallet response missing address or private material for '.$normalized);
            }

            $wallet = UserWallet::create([
                'user_id' => $userId,
                'blockchain' => $normalized,
                'mnemonic_encrypted' => $this->encryption->encrypt($pk),
                'xpub' => $address,
                'derivation_path' => null,
            ]);
            $this->assertEncryptedSecretRoundTrips($wallet->mnemonic_encrypted);

            return $wallet;
        }

        $mnemonic = $data['mnemonic'] ?? '';
        $xpub = $data['xpub'] ?? '';
        if ($mnemonic === '' || $xpub === '') {
            throw new RuntimeException('Tatum wallet response missing mnemonic or xpub for '.$normalized);
        }

        $wallet = UserWallet::create([
            'user_id' => $userId,
            'blockchain' => $normalized,
            'mnemonic_encrypted' => $this->encryption->encrypt($mnemonic),
            'xpub' => $xpub,
            'derivation_path' => null,
        ]);
        $this->assertEncryptedSecretRoundTrips($wallet->mnemonic_encrypted);

        return $wallet;
    }

    /**
     * Fail fast if ENCRYPTION_KEY cannot decrypt what we just stored (misconfiguration).
     */
    protected function assertEncryptedSecretRoundTrips(string $encrypted): void
    {
        $plain = $this->encryption->decrypt($encrypted);
        if ($plain === '') {
            throw new RuntimeException('Decrypted wallet secret is empty after storage.');
        }
    }

    public function decryptMnemonicOrSecret(UserWallet $wallet): string
    {
        if (! $wallet->mnemonic_encrypted) {
            throw new RuntimeException('User wallet has no encrypted secret.');
        }

        return $this->encryption->decrypt($wallet->mnemonic_encrypted);
    }
}
