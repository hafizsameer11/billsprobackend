<?php

namespace App\Jobs;

use App\Models\CryptoDepositAddress;
use App\Models\MasterWallet;
use App\Models\ReceivedAsset;
use App\Models\TatumRawWebhook;
use App\Models\TatumWebhookResponse;
use App\Models\Transaction;
use App\Models\VirtualAccount;
use App\Models\WalletCurrency;
use App\Services\Crypto\AllowedCryptoDepositResolver;
use App\Services\Crypto\CryptoService;
use App\Services\Tatum\DepositAddressService;
use App\Services\Tatum\TatumWebhookPayloadNormalizer;
use App\Services\Tatum\TatumWebhookProcessingOutcome;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessTatumWebhookJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public int $tatumRawWebhookId
    ) {}

    public function uniqueId(): string
    {
        return 'tatum-raw-webhook-'.$this->tatumRawWebhookId;
    }

    public function handle(TatumWebhookPayloadNormalizer $normalizer): void
    {
        $raw = TatumRawWebhook::query()->find($this->tatumRawWebhookId);
        if (! $raw) {
            return;
        }

        /** @var array<string, mixed> $data */
        $data = json_decode($raw->raw_data, true) ?? [];
        $data = $normalizer->normalize($data);

        $metadata = [
            'tx_id' => $normalizer->extractTxId($data),
            'subscription_type' => $normalizer->inferSubscriptionType($data) ?: null,
            'receiving_address' => $normalizer->extractReceivingAddress($data),
            'channel' => $raw->channel ?? $normalizer->inferChannel($data),
        ];

        try {
            $outcome = $this->processPayload($data);
            $raw->update([
                'processed' => TatumWebhookProcessingOutcome::isTerminal($outcome),
                'processed_at' => now(),
                'error_message' => null,
                'outcome' => $outcome,
                ...$metadata,
            ]);
        } catch (Throwable $e) {
            Log::error('ProcessTatumWebhookJob failed', [
                'id' => $this->tatumRawWebhookId,
                'message' => $e->getMessage(),
            ]);
            $raw->update([
                'processed' => false,
                'processed_at' => null,
                'error_message' => $e->getMessage(),
                'outcome' => 'failed_exception',
                ...$metadata,
            ]);

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function processPayload(array $data): string
    {
        $normalizer = app(TatumWebhookPayloadNormalizer::class);
        $subscriptionType = $normalizer->inferSubscriptionType($data);

        if ($this->shouldSkipNonDepositPayload($data, $subscriptionType)) {
            Log::info('Tatum webhook ignored (non-deposit event)', [
                'txId' => $data['txId'] ?? $data['txHash'] ?? null,
                'subscriptionType' => $subscriptionType,
                'type' => $data['type'] ?? null,
            ]);

            return TatumWebhookProcessingOutcome::IGNORED_NON_DEPOSIT;
        }

        $txId = (string) ($data['txId'] ?? $data['txHash'] ?? $data['hash'] ?? '');
        if ($txId === '') {
            throw new \RuntimeException('Missing tx id in webhook payload');
        }

        $allowlist = app(AllowedCryptoDepositResolver::class);

        $masterAddresses = MasterWallet::query()
            ->whereNotNull('address')
            ->pluck('address')
            ->map(fn (string $a) => trim($a))
            ->filter(fn (string $a) => $a !== '')
            ->values()
            ->all();

        $isDepositWebhook = in_array($subscriptionType, [
            'ADDRESS_EVENT',
            'INCOMING_NATIVE_TX',
            'INCOMING_FUNGIBLE_TX',
            'ACCOUNT_INCOMING_BLOCKCHAIN_TRANSACTION',
        ], true);

        if (! $isDepositWebhook) {
            throw new \RuntimeException('Unsupported subscription type: '.$subscriptionType);
        }

        $webhookAddress = $normalizer->extractReceivingAddress($data);
        if (! $webhookAddress) {
            throw new \RuntimeException('Missing receiving address in webhook payload');
        }

        if ($this->isMasterAddress((string) $webhookAddress, $masterAddresses)) {
            Log::info('Tatum webhook ignored (monitored address is master wallet)', [
                'txId' => $txId,
                'address' => $webhookAddress,
            ]);

            return TatumWebhookProcessingOutcome::IGNORED_MASTER_WALLET;
        }

        $counterparty = $this->resolveCounterpartyAddress($data);
        if ($counterparty === null || $counterparty === '') {
            if ($subscriptionType === 'ADDRESS_EVENT') {
                Log::info('Tatum webhook ignored (ADDRESS_EVENT without counterparty)', ['txId' => $txId]);

                return TatumWebhookProcessingOutcome::IGNORED_NO_SENDER;
            }
            if (! $this->allowsMissingSender($subscriptionType)) {
                Log::info('Tatum webhook ignored (no counterparty / sender)', ['txId' => $txId]);

                return TatumWebhookProcessingOutcome::IGNORED_NO_SENDER;
            }
            $counterparty = '';
        } elseif ($this->isMasterAddress($counterparty, $masterAddresses)) {
            Log::info('Tatum webhook ignored (sender is master wallet)', ['txId' => $txId]);

            return TatumWebhookProcessingOutcome::IGNORED_MASTER_WALLET;
        }

        $contractAddress = $allowlist->extractContractAddress($data);
        $isIncomingFungible = $allowlist->isFungiblePayload($data, $subscriptionType);
        $amountStr = $this->resolveIncomingAmountString($data, $isIncomingFungible);

        $depositRow = DepositAddressService::findByIncomingAddress((string) $webhookAddress);
        if (! $depositRow || ! $depositRow->virtualAccount) {
            Log::info('Tatum webhook: deposit address not found', ['address' => $webhookAddress, 'txId' => $txId]);

            return TatumWebhookProcessingOutcome::IGNORED_UNKNOWN_ADDRESS;
        }

        $baseBlockchain = DepositAddressService::normalizeBlockchain((string) $depositRow->blockchain);
        $userId = $depositRow->virtualAccount->user_id;

        $virtualAccount = $depositRow->virtualAccount;
        $detectedCurrency = $virtualAccount->currency;
        $currencyHint = $allowlist->extractNativeCurrencyHint($data);

        if ($isIncomingFungible) {
            $wcMatch = $allowlist->resolveAllowedTokenDeposit($baseBlockchain, $data, $subscriptionType);
            if (! $wcMatch) {
                $this->logRejectedUnlistedAsset($txId, $contractAddress, $baseBlockchain, (string) $webhookAddress, $data);

                return TatumWebhookProcessingOutcome::IGNORED_UNLISTED_ASSET;
            }
            $detectedCurrency = $wcMatch->currency;
            $betterVa = VirtualAccount::query()
                ->where('user_id', $userId)
                ->where('currency', $wcMatch->currency)
                ->whereRaw('LOWER(blockchain) = ?', [strtolower($wcMatch->blockchain)])
                ->where('active', true)
                ->first();
            if ($betterVa) {
                $virtualAccount = $betterVa;
            }
        } else {
            $wcNative = null;
            if ($currencyHint !== null) {
                $wcNative = $allowlist->resolveAllowedNativeByCurrency($baseBlockchain, $currencyHint);
            }
            $wcNative ??= $allowlist->resolveAllowedNative($baseBlockchain, $virtualAccount);
            if (! $wcNative) {
                $this->logRejectedUnlistedAsset($txId, $contractAddress, $baseBlockchain, (string) $webhookAddress, $data);

                return TatumWebhookProcessingOutcome::IGNORED_UNLISTED_ASSET;
            }
            $detectedCurrency = $wcNative->currency;
            $virtualAccount = VirtualAccount::query()
                ->where('user_id', $userId)
                ->where('currency', $wcNative->currency)
                ->whereRaw('LOWER(blockchain) = ?', [strtolower($wcNative->blockchain)])
                ->where('active', true)
                ->first() ?? $virtualAccount;
        }

        $timestamp = $data['timestamp'] ?? $data['date'] ?? $data['txTimestamp'] ?? $data['blockTimestamp'] ?? null;
        $transactionDate = now();
        if (is_numeric($timestamp)) {
            $ts = (int) $timestamp;
            if ($ts > 0 && $ts < 1_000_000_000_000) {
                $ts *= 1000;
            }
            try {
                $transactionDate = \Carbon\Carbon::createFromTimestampMs($ts);
            } catch (\Throwable) {
                $transactionDate = now();
            }
        }

        try {
            TatumWebhookResponse::query()->create([
                'account_id' => $virtualAccount->account_id,
                'subscription_type' => $subscriptionType,
                'amount' => is_numeric($amountStr) ? $amountStr : 0,
                'reference' => isset($data['reference']) ? (string) $data['reference'] : null,
                'currency' => $detectedCurrency,
                'tx_id' => $txId,
                'block_height' => isset($data['blockNumber']) ? (int) $data['blockNumber'] : (isset($data['blockHeight']) ? (int) $data['blockHeight'] : null),
                'block_hash' => isset($data['blockHash']) ? (string) $data['blockHash'] : null,
                'from_address' => $counterparty,
                'to_address' => (string) $webhookAddress,
                'transaction_date' => $transactionDate,
                'index' => isset($data['logIndex']) ? (int) $data['logIndex'] : (isset($data['index']) ? (int) $data['index'] : null),
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            $msg = strtolower($e->getMessage());
            if (str_contains($msg, 'unique') || str_contains($msg, 'duplicate')) {
                return TatumWebhookProcessingOutcome::DUPLICATE_TX;
            }

            throw $e;
        }

        $amount = (float) $amountStr;
        if ($amount <= 0) {
            Log::warning('Tatum webhook: non-positive amount', ['txId' => $txId, 'amount' => $amountStr]);

            return TatumWebhookProcessingOutcome::FAILED_ZERO_AMOUNT;
        }

        if (Transaction::query()
            ->where('type', 'crypto_deposit')
            ->where('deposit_tx_hash', $txId)
            ->exists()) {
            return TatumWebhookProcessingOutcome::DUPLICATE_TX;
        }

        $this->creditVirtualAccountAndLedger(
            $virtualAccount,
            $userId,
            $detectedCurrency,
            $amount,
            $txId,
            $subscriptionType,
            $counterparty,
            (string) $webhookAddress,
            $baseBlockchain,
            $this->logIndexFromPayload($data)
        );

        return TatumWebhookProcessingOutcome::CREDITED;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function shouldSkipNonDepositPayload(array $data, string $subscriptionType): bool
    {
        $payloadType = strtolower((string) ($data['type'] ?? ''));
        if (in_array($payloadType, ['fee', 'internal', 'failed'], true)) {
            return true;
        }

        $amount = (string) ($data['amount'] ?? '');
        if ($amount !== '' && str_starts_with($amount, '-')) {
            return true;
        }

        return str_starts_with($subscriptionType, 'OUTGOING_');
    }

    protected function allowsMissingSender(string $subscriptionType): bool
    {
        return in_array($subscriptionType, [
            'INCOMING_NATIVE_TX',
            'INCOMING_FUNGIBLE_TX',
            'ACCOUNT_INCOMING_BLOCKCHAIN_TRANSACTION',
        ], true);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function logRejectedUnlistedAsset(
        string $txId,
        ?string $contractAddress,
        string $blockchain,
        string $depositAddress,
        array $data
    ): void {
        Log::warning('tatum_webhook.rejected_unlisted_asset', [
            'txId' => $txId,
            'contractAddress' => $contractAddress,
            'blockchain' => $blockchain,
            'address' => $depositAddress,
            'subscriptionType' => $data['subscriptionType'] ?? $data['type'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function logIndexFromPayload(array $data): int
    {
        if (isset($data['logIndex']) && is_numeric($data['logIndex'])) {
            return (int) $data['logIndex'];
        }
        if (isset($data['index']) && is_numeric($data['index'])) {
            return (int) $data['index'];
        }

        return 0;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function resolveCounterpartyAddress(array $data): ?string
    {
        $candidates = [
            $data['from'] ?? null,
            $data['counterAddress'] ?? null,
            $data['counteraddress'] ?? null,
            $data['counter_address'] ?? null,
        ];
        if (! empty($data['counterAddresses']) && is_array($data['counterAddresses'])) {
            foreach ($data['counterAddresses'] as $item) {
                if (is_string($item) && $item !== '') {
                    $candidates[] = $item;
                }
                if (is_array($item) && isset($item['address']) && is_string($item['address'])) {
                    $candidates[] = $item['address'];
                }
            }
        }
        foreach ($candidates as $c) {
            if (is_string($c) && trim($c) !== '') {
                return $c;
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $masterAddresses
     */
    protected function isMasterAddress(string $address, array $masterAddresses): bool
    {
        $trimmed = trim($address);
        if ($trimmed === '') {
            return false;
        }

        foreach ($masterAddresses as $master) {
            if ($trimmed === $master) {
                return true;
            }
            if (str_starts_with($trimmed, '0x') && str_starts_with($master, '0x')) {
                if (strtolower($trimmed) === strtolower($master)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function resolveIncomingAmountString(array $data, bool $isIncomingFungible): string
    {
        if (! $isIncomingFungible) {
            if (array_key_exists('amount', $data) && $data['amount'] !== '' && $data['amount'] !== null && is_numeric($data['amount'])) {
                return (string) $data['amount'];
            }

            if (array_key_exists('value', $data) && $data['value'] !== '' && $data['value'] !== null) {
                $valueStr = (string) $data['value'];
                if ($this->looksLikeRawChainAmount($valueStr)) {
                    return $this->rawAmountToDecimalString($valueStr, $this->resolveNativeDecimals($data));
                }
                if (is_numeric($valueStr)) {
                    return $valueStr;
                }
            }

            return '0';
        }

        $tokenId = $data['tokenId'] ?? $data['token_id'] ?? null;
        $hasRawTokenAmount = $tokenId !== null && $tokenId !== '' && is_numeric($tokenId);

        if ($isIncomingFungible && $hasRawTokenAmount) {
            $decimals = $this->resolveTokenDecimals($data);

            return $this->rawAmountToDecimalString((string) $tokenId, $decimals);
        }

        foreach (['amount', 'value'] as $key) {
            if (! array_key_exists($key, $data) || $data[$key] === '' || $data[$key] === null) {
                continue;
            }
            if (is_numeric($data[$key])) {
                return (string) $data[$key];
            }
        }

        return '0';
    }

    protected function looksLikeRawChainAmount(string $value): bool
    {
        $trimmed = trim($value);
        if ($trimmed === '' || str_contains($trimmed, '.')) {
            return false;
        }

        if (! ctype_digit($trimmed)) {
            return false;
        }

        return strlen($trimmed) > 10;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function resolveNativeDecimals(array $data): int
    {
        if (isset($data['tokenMetadata']) && is_array($data['tokenMetadata'])) {
            $d = (int) ($data['tokenMetadata']['decimals'] ?? 18);

            return max(0, min(36, $d));
        }

        $chain = strtolower((string) ($data['chain'] ?? ''));
        if (str_contains($chain, 'bitcoin') || str_contains($chain, 'doge')) {
            return 8;
        }
        if (str_contains($chain, 'tron')) {
            return 6;
        }

        return 18;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function resolveTokenDecimals(array $data): int
    {
        if (isset($data['tokenMetadata']) && is_array($data['tokenMetadata'])) {
            $d = (int) ($data['tokenMetadata']['decimals'] ?? 18);

            return max(0, min(36, $d));
        }
        if (isset($data['decimals']) && is_numeric($data['decimals'])) {
            return max(0, min(36, (int) $data['decimals']));
        }

        return 18;
    }

    protected function rawAmountToDecimalString(string $raw, int $decimals): string
    {
        if ($decimals <= 0) {
            return $raw;
        }
        if (function_exists('bcdiv') && function_exists('bcpow')) {
            $divisor = bcpow('10', (string) $decimals, 0);
            $scale = min(36, $decimals + 8);
            $out = bcdiv($raw, $divisor, $scale);

            return $this->trimTrailingZeros($out);
        }

        $rawFloat = (float) $raw;
        $div = 10 ** $decimals;

        return $this->trimTrailingZeros(sprintf('%.'.$decimals.'f', $rawFloat / $div));
    }

    protected function trimTrailingZeros(string $num): string
    {
        if (str_contains($num, '.')) {
            $num = rtrim(rtrim($num, '0'), '.');
        }

        return $num !== '' ? $num : '0';
    }

    protected function creditVirtualAccountAndLedger(
        VirtualAccount $virtualAccount,
        int $userId,
        string $currency,
        float $amount,
        string $txId,
        string $subscriptionType,
        string $fromAddress,
        string $toAddress,
        string $baseBlockchain,
        int $logIndex = 0
    ): void {
        DB::transaction(function () use (
            $virtualAccount,
            $userId,
            $currency,
            $amount,
            $txId,
            $subscriptionType,
            $fromAddress,
            $toAddress,
            $baseBlockchain,
            $logIndex
        ) {
            if (Transaction::query()
                ->where('type', 'crypto_deposit')
                ->where('deposit_tx_hash', $txId)
                ->exists()) {
                return;
            }

            $account = VirtualAccount::query()
                ->whereKey($virtualAccount->id)
                ->lockForUpdate()
                ->firstOrFail();

            $currentAvailable = (float) ($account->available_balance ?? '0');
            $currentAccount = (float) ($account->account_balance ?? '0');

            $settlement = app(CryptoService::class)->computeOnChainDepositSettlement(
                $amount,
                $currency,
                (string) $account->blockchain
            );
            $netCredit = (float) $settlement['net_crypto'];
            $feeCrypto = (float) $settlement['fee_crypto'];

            $newAvailable = $currentAvailable + $netCredit;
            $newAccount = $currentAccount + $netCredit;

            $account->available_balance = (string) $newAvailable;
            $account->account_balance = (string) $newAccount;
            $account->save();

            $walletCurrency = WalletCurrency::query()
                ->where('currency', $currency)
                ->whereRaw('LOWER(blockchain) = ?', [strtolower($account->blockchain)])
                ->with('exchangeRate')
                ->first();

            $rate = $walletCurrency ? $walletCurrency->usdPerUnitForDisplay() : 1.0;
            $grossUsd = $amount * $rate;
            $netUsd = $netCredit * $rate;
            $amountNgn = $netUsd * (float) config('crypto.ngn_per_usd', CryptoService::DEFAULT_EXCHANGE_RATE);

            $tx = Transaction::query()->create([
                'user_id' => $userId,
                'transaction_id' => Transaction::generateTransactionId(),
                'type' => 'crypto_deposit',
                'category' => 'on_chain_receive',
                'status' => 'completed',
                'currency' => $currency,
                'amount' => $netCredit,
                'fee' => $feeCrypto,
                'total_amount' => $netCredit,
                'reference' => Transaction::generateTransactionId(),
                'deposit_tx_hash' => $txId,
                'description' => "On-chain deposit {$netCredit} {$currency}".($feeCrypto > 0 ? ' (after processing fee)' : ''),
                'metadata' => [
                    'blockchain' => $account->blockchain,
                    'network' => $baseBlockchain,
                    'tx_hash' => $txId,
                    'from_address' => $fromAddress,
                    'to_address' => $toAddress,
                    'subscription_type' => $subscriptionType,
                    'virtual_account_id' => $account->id,
                    'gross_amount_crypto' => $amount,
                    'processing_fee_crypto' => $feeCrypto,
                    'processing_fee_usd' => $settlement['fee_usd'],
                    'amount_usd' => round($netUsd, 8),
                    'gross_amount_usd' => round($grossUsd, 8),
                    'amount_ngn' => round($amountNgn, 2),
                    'received_asset_log_index' => $logIndex,
                ],
                'completed_at' => now(),
            ]);

            $depositAddr = DepositAddressService::findByIncomingAddress($toAddress);
            if (! $depositAddr || (int) $depositAddr->virtual_account_id !== (int) $account->id) {
                $depositAddr = CryptoDepositAddress::query()
                    ->where('virtual_account_id', $account->id)
                    ->orderByDesc('id')
                    ->first();
            }

            ReceivedAsset::query()->create([
                'user_id' => $userId,
                'virtual_account_id' => $account->id,
                'transaction_id' => $tx->id,
                'crypto_deposit_address_id' => $depositAddr?->id,
                'blockchain' => (string) $account->blockchain,
                'currency' => $currency,
                'amount' => $amount,
                'tx_hash' => $txId,
                'log_index' => $logIndex,
                'from_address' => $fromAddress,
                'to_address' => $toAddress,
                'source' => 'tatum_webhook',
                'status' => 'received',
                'metadata' => [
                    'subscription_type' => $subscriptionType,
                    'network' => $baseBlockchain,
                ],
            ]);
        });
    }
}
