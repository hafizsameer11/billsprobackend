<?php

namespace App\Services\Crypto;

use App\Models\VirtualAccount;
use App\Models\WalletCurrency;
use App\Services\Tatum\DepositAddressService;

class AllowedCryptoDepositResolver
{
    /**
     * Resolve an allowed native (chain coin) deposit for this virtual account.
     */
    public function resolveAllowedNative(string $baseBlockchain, VirtualAccount $virtualAccount): ?WalletCurrency
    {
        $chainLower = strtolower(DepositAddressService::normalizeBlockchain($baseBlockchain));

        return WalletCurrency::query()
            ->whereRaw('LOWER(blockchain) = ?', [$chainLower])
            ->where('currency', $virtualAccount->currency)
            ->where('is_token', false)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Resolve allowlisted token deposit for crediting.
     *
     * Security: when a contract address is present it is authoritative — a mismatched
     * contract is rejected even if currency/symbol/metadata claim USDT/USDC.
     * Currency-only resolution is allowed only for v3 ledger webhooks that omit contracts.
     */
    public function resolveAllowedTokenDeposit(string $baseBlockchain, array $data, string $subscriptionType): ?WalletCurrency
    {
        $contract = $this->extractContractAddress($data);

        if ($contract !== null) {
            return $this->resolveAllowedTokenByContract($baseBlockchain, $contract);
        }

        if ($subscriptionType !== 'ACCOUNT_INCOMING_BLOCKCHAIN_TRANSACTION') {
            return null;
        }

        $currencyHint = $this->extractNativeCurrencyHint($data);
        if ($currencyHint === null) {
            return null;
        }

        return $this->resolveAllowedTokenByCurrency($baseBlockchain, $currencyHint);
    }

    public function hasUnlistedContract(array $data, string $baseBlockchain): bool
    {
        $contract = $this->extractContractAddress($data);
        if ($contract === null) {
            return false;
        }

        return $this->resolveAllowedTokenByContract($baseBlockchain, $contract) === null;
    }

    /**
     * Resolve an allowed token deposit by exact contract address match.
     */
    public function resolveAllowedTokenByContract(string $baseBlockchain, ?string $contractAddress): ?WalletCurrency
    {
        if ($contractAddress === null || trim($contractAddress) === '') {
            return null;
        }

        $contract = trim($contractAddress);
        if (strtoupper($contract) === 'ETH') {
            return null;
        }

        $chainLower = strtolower(DepositAddressService::normalizeBlockchain($baseBlockchain));

        $candidates = WalletCurrency::query()
            ->whereRaw('LOWER(blockchain) = ?', [$chainLower])
            ->where('is_token', true)
            ->where('is_active', true)
            ->whereNotNull('contract_address')
            ->get();

        foreach ($candidates as $wc) {
            if ($this->contractsMatch((string) $wc->contract_address, $contract)) {
                return $wc;
            }
        }

        return $this->resolveFromConfigContracts($chainLower, $contract);
    }

    protected function resolveFromConfigContracts(string $chainLower, string $contract): ?WalletCurrency
    {
        $contracts = config('tatum.contracts', []);
        if (! is_array($contracts)) {
            return null;
        }

        $chainKey = $chainLower === 'eth' ? 'ethereum' : $chainLower;
        $chainContracts = $contracts[$chainKey] ?? $contracts[$chainLower] ?? null;
        if (! is_array($chainContracts)) {
            return null;
        }

        foreach ($chainContracts as $currencyCode => $knownAddress) {
            if (! is_string($knownAddress) || ! $this->contractsMatch($knownAddress, $contract)) {
                continue;
            }

            $currency = strtoupper((string) $currencyCode);
            if ($currency === 'USDT_SOL') {
                $currency = 'USDT';
            }

            $wc = WalletCurrency::query()
                ->whereRaw('LOWER(blockchain) = ?', [$chainLower])
                ->where('currency', $currency)
                ->where('is_token', true)
                ->where('is_active', true)
                ->whereNotNull('contract_address')
                ->first();

            if ($wc && $this->contractsMatch((string) $wc->contract_address, $contract)) {
                return $wc;
            }
        }

        return null;
    }

    public function contractsMatch(string $allowed, string $incoming): bool
    {
        $a = strtolower(trim($allowed));
        $b = strtolower(trim($incoming));

        if ($a === '' || $b === '') {
            return false;
        }

        if (str_starts_with($a, '0x') && str_starts_with($b, '0x')) {
            return $a === $b;
        }

        return strcasecmp($a, $b) === 0;
    }

    public function isFungiblePayload(array $data, string $subscriptionType): bool
    {
        if (isset($data['tokenMetadata']) && is_array($data['tokenMetadata'])) {
            $tokenType = strtolower((string) ($data['tokenMetadata']['type'] ?? ''));
            if ($tokenType === 'fungible') {
                return true;
            }
            if ($tokenType === 'native') {
                return false;
            }
        }

        $payloadType = strtolower((string) ($data['type'] ?? ''));
        if (in_array($payloadType, ['native'], true)) {
            return false;
        }
        if (in_array($payloadType, ['token', 'fungible', 'erc20', 'trc20', 'bep20', 'trc10'], true)) {
            return true;
        }

        if (in_array($subscriptionType, ['INCOMING_FUNGIBLE_TX'], true)) {
            return true;
        }

        if (($data['kind'] ?? '') === 'token_transfer') {
            return true;
        }

        $currency = strtoupper(trim((string) ($data['currency'] ?? '')));
        if (in_array($currency, ['USDT', 'USDT_TRON', 'USDT_BSC', 'USDT_SOL', 'USDC', 'USDC_BSC'], true)) {
            return true;
        }

        $contract = $data['contractAddress'] ?? $data['contract_address'] ?? null;
        if (is_string($contract) && trim($contract) !== '' && strtoupper(trim($contract)) !== 'ETH') {
            $tokenId = $data['tokenId'] ?? $data['token_id'] ?? null;
            if ($tokenId !== null && $tokenId !== '') {
                return true;
            }

            if ($subscriptionType === 'ADDRESS_EVENT' || $subscriptionType === 'ACCOUNT_INCOMING_BLOCKCHAIN_TRANSACTION') {
                return true;
            }
        }

        $asset = $data['asset'] ?? null;
        if (is_string($asset) && str_starts_with(trim($asset), '0x') && strlen(trim($asset)) >= 40) {
            return true;
        }

        return false;
    }

    /**
     * Map Tatum webhook currency codes to ledger `wallet_currencies.currency`.
     */
    public function normalizeWebhookCurrencyCode(string $currency, string $baseBlockchain): string
    {
        $code = strtoupper(trim($currency));
        $chainLower = strtolower(DepositAddressService::normalizeBlockchain($baseBlockchain));

        if ($code === 'TRON' && $chainLower === 'tron') {
            return 'TRX';
        }

        return $code;
    }

    public function resolveAllowedNativeByCurrency(string $baseBlockchain, string $currencyCode): ?WalletCurrency
    {
        $chainLower = strtolower(DepositAddressService::normalizeBlockchain($baseBlockchain));
        $currency = $this->normalizeWebhookCurrencyCode($currencyCode, $chainLower);

        return WalletCurrency::query()
            ->whereRaw('LOWER(blockchain) = ?', [$chainLower])
            ->where('currency', $currency)
            ->where('is_token', false)
            ->where('is_active', true)
            ->first();
    }

    public function resolveAllowedTokenByCurrency(string $baseBlockchain, string $currencyCode): ?WalletCurrency
    {
        $chainLower = strtolower(DepositAddressService::normalizeBlockchain($baseBlockchain));
        $currency = strtoupper(trim($currencyCode));

        return WalletCurrency::query()
            ->whereRaw('LOWER(blockchain) = ?', [$chainLower])
            ->where('currency', $currency)
            ->where('is_token', true)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Best-effort native currency symbol from webhook (ETH, BTC, TRON→TRX, etc.).
     */
    public function extractNativeCurrencyHint(array $data): ?string
    {
        foreach (['currency', 'asset'] as $key) {
            $val = $data[$key] ?? null;
            if (! is_string($val) || trim($val) === '') {
                continue;
            }
            $trimmed = trim($val);
            if (str_starts_with($trimmed, '0x') && strlen($trimmed) >= 40) {
                continue;
            }

            return $trimmed;
        }

        return null;
    }

    public function extractContractAddress(array $data): ?string
    {
        foreach (['contractAddress', 'contract_address', 'asset'] as $key) {
            $val = $data[$key] ?? null;
            if (is_string($val) && trim($val) !== '' && strtoupper(trim($val)) !== 'ETH') {
                return trim($val);
            }
        }

        return null;
    }
}
