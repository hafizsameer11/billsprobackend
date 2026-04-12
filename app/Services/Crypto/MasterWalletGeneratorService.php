<?php

namespace App\Services\Crypto;

use App\Models\MasterWallet;
use App\Models\MasterWalletSecret;
use App\Services\Tatum\DepositAddressService;
use App\Services\Tatum\TatumClient;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class MasterWalletGeneratorService
{
    public function __construct(
        protected KeyEncryptionService $encryption
    ) {}

    /**
     * Tatum V3 wallet API path segment (e.g. "ethereum", "bsc").
     */
    public function tatumWalletApiPath(string $normalizedBlockchain): string
    {
        $b = strtolower(trim($normalizedBlockchain));

        return match ($b) {
            'ethereum', 'eth' => 'ethereum',
            'bsc', 'binance', 'binancesmartchain' => 'bsc',
            'bitcoin', 'btc' => 'bitcoin',
            'litecoin', 'ltc' => 'litecoin',
            'tron', 'trx' => 'tron',
            'polygon', 'matic' => 'polygon',
            'solana', 'sol' => 'solana',
            'xrp', 'ripple' => 'xrp',
            'dogecoin', 'doge' => 'dogecoin',
            default => $b,
        };
    }

    /**
     * Create or replace a master wallet + encrypted secrets via Tatum.
     */
    public function generate(string $blockchainInput, bool $force = false, ?string $label = null): MasterWallet
    {
        if (config('tatum.use_mock')) {
            throw new RuntimeException('Cannot generate master wallets while TATUM_USE_MOCK is true.');
        }

        $normalized = DepositAddressService::normalizeBlockchain($blockchainInput);
        $existing = MasterWallet::query()->where('blockchain', $normalized)->first();

        if ($existing) {
            if (! $force) {
                throw new RuntimeException(
                    "Master wallet already exists for blockchain \"{$normalized}\". Use --force to replace (deletes existing row)."
                );
            }
            $existing->delete();
        }

        $tatumPath = $this->tatumWalletApiPath($normalized);
        $tatum = TatumClient::fromConfig();
        $data = $tatum->createWallet($tatumPath);

        // HD chains: Tatum `GET /v3/{chain}/wallet` often returns mnemonic + xpub only; address is then
        // `GET /v3/{chain}/address/{xpub}/0`. Non-HD (e.g. Solana): response includes `address` + `privateKey`
        // directly; XRP uses `GET /v3/xrp/account` (handled in TatumClient) — do not derive via xpub.
        $address = (string) ($data['address'] ?? '');
        $xpub = isset($data['xpub']) ? (string) $data['xpub'] : null;
        if ($address === '' && $xpub !== null && $xpub !== '' && $this->shouldDeriveAddressFromXpub($tatumPath)) {
            $address = $tatum->generateAddress($tatumPath, $xpub, 0);
        }
        if ($address === '') {
            throw new RuntimeException(
                'Tatum did not return an address for '.$tatumPath
                .' (expected `address` on wallet response, or `xpub` for HD derivation at index 0).'
            );
        }

        $privateKey = $this->resolvePrivateKey($tatum, $tatumPath, $data);
        if ($privateKey === '') {
            throw new RuntimeException('Resolved empty private key for '.$tatumPath);
        }
        $mnemonic = $data['mnemonic'] ?? null;

        return DB::transaction(function () use ($normalized, $address, $privateKey, $mnemonic, $xpub, $label) {
            $wallet = MasterWallet::create([
                'blockchain' => $normalized,
                'address' => $address,
                'label' => $label ?? 'Master '.$normalized,
            ]);

            MasterWalletSecret::create([
                'master_wallet_id' => $wallet->id,
                'mnemonic_encrypted' => $mnemonic ? $this->encryption->encrypt($mnemonic) : null,
                'xpub_encrypted' => $xpub ? $this->encryption->encrypt($xpub) : null,
                'private_key_encrypted' => $this->encryption->encrypt($privateKey),
            ]);

            return $wallet->load('secret');
        });
    }

    /**
     * Chains where Tatum follows BIP44-style HD: wallet returns xpub + mnemonic, address at index 0 via
     * `/v3/{chain}/address/{xpub}/0`. Solana (and similar) return address + privateKey in one response — no xpub path.
     */
    protected function shouldDeriveAddressFromXpub(string $tatumPath): bool
    {
        return match (strtolower($tatumPath)) {
            'solana' => false,
            default => true,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function resolvePrivateKey(TatumClient $tatum, string $tatumPath, array $data): string
    {
        if (! empty($data['privateKey'])) {
            return (string) $data['privateKey'];
        }
        if (! empty($data['secret'])) {
            return (string) $data['secret'];
        }
        $mnemonic = $data['mnemonic'] ?? '';
        if ($mnemonic !== '') {
            return $tatum->generatePrivateKey($tatumPath, $mnemonic, 0);
        }

        throw new RuntimeException(
            'Tatum wallet response did not include privateKey/secret/mnemonic for '.$tatumPath.'. Cannot derive signing key.'
        );
    }
}
