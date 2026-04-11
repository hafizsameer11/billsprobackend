<?php

namespace App\Jobs;

use App\Models\CryptoDepositAddress;
use App\Models\MasterWallet;
use App\Models\TatumRawWebhook;
use App\Models\TatumWebhookResponse;
use App\Models\Transaction;
use App\Models\VirtualAccount;
use App\Models\WalletCurrency;
use App\Services\Crypto\CryptoService;
use App\Services\Tatum\DepositAddressService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessTatumWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $tatumRawWebhookId
    ) {}

    public function handle(): void
    {
        $raw = TatumRawWebhook::query()->find($this->tatumRawWebhookId);
        if (! $raw) {
            return;
        }

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($raw->raw_data, true) ?? [];
            $this->processPayload($data);
            $raw->update([
                'processed' => true,
                'processed_at' => now(),
                'error_message' => null,
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
            ]);

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function processPayload(array $data): void
    {
        $subscriptionType = (string) ($data['subscriptionType'] ?? $data['type'] ?? '');
        $txId = (string) ($data['txId'] ?? $data['txHash'] ?? $data['hash'] ?? '');

        if ($txId === '') {
            throw new \RuntimeException('Missing tx id in webhook payload');
        }

        if (Transaction::query()
            ->where('type', 'crypto_deposit')
            ->where('metadata->tx_hash', $txId)
            ->exists()) {
            return;
        }

        $masterAddresses = MasterWallet::query()
            ->whereNotNull('address')
            ->pluck('address')
            ->map(fn (string $a) => strtolower($a))
            ->all();

        $accountIdField = $data['accountId'] ?? $data['account_id'] ?? null;
        if (is_string($accountIdField) && $accountIdField !== '') {
            $va = VirtualAccount::query()
                ->where('account_id', $accountIdField)
                ->where('active', true)
                ->with('walletCurrency')
                ->first();
            if ($va) {
                $this->processAccountIdIncoming($data, $va, $subscriptionType, $txId, $masterAddresses);

                return;
            }
        }

        $isAddressWebhook = in_array($subscriptionType, [
            'ADDRESS_EVENT',
            'INCOMING_NATIVE_TX',
            'INCOMING_FUNGIBLE_TX',
        ], true);

        if (! $isAddressWebhook) {
            throw new \RuntimeException('Unsupported subscription type: '.$subscriptionType);
        }

        $webhookAddress = $data['address'] ?? $data['to'] ?? null;
        if (! $webhookAddress) {
            throw new \RuntimeException('Missing receiving address in webhook payload');
        }

        if ($this->isMasterAddress((string) $webhookAddress, $masterAddresses)) {
            Log::info('Tatum webhook ignored (monitored address is master wallet)', [
                'txId' => $txId,
                'address' => $webhookAddress,
            ]);

            return;
        }

        $counterparty = $this->resolveCounterpartyAddress($data);
        if ($counterparty === null || $counterparty === '') {
            if ($subscriptionType === 'ADDRESS_EVENT') {
                Log::info('Tatum webhook ignored (ADDRESS_EVENT without counterparty)', ['txId' => $txId]);

                return;
            }
            Log::info('Tatum webhook ignored (no counterparty / sender)', ['txId' => $txId]);

            return;
        }

        if ($this->isMasterAddress($counterparty, $masterAddresses)) {
            Log::info('Tatum webhook ignored (sender is master wallet)', ['txId' => $txId]);

            return;
        }

        $webhookAddrLower = strtolower((string) $webhookAddress);
        $amountStr = (string) ($data['amount'] ?? '0');

        $depositRow = CryptoDepositAddress::query()
            ->whereRaw('LOWER(address) = ?', [$webhookAddrLower])
            ->with(['virtualAccount.walletCurrency'])
            ->first();

        if (! $depositRow || ! $depositRow->virtualAccount) {
            Log::info('Tatum webhook: deposit address not found', ['address' => $webhookAddress, 'txId' => $txId]);

            return;
        }

        $baseBlockchain = DepositAddressService::normalizeBlockchain((string) $depositRow->blockchain);
        $userId = $depositRow->virtualAccount->user_id;

        $contractAddress = $data['contractAddress'] ?? $data['asset'] ?? null;
        $isIncomingFungible = $subscriptionType === 'INCOMING_FUNGIBLE_TX';

        $virtualAccount = $depositRow->virtualAccount;
        $detectedCurrency = $virtualAccount->currency;

        if ($isIncomingFungible) {
            $wcMatch = $this->resolveFungibleWalletCurrency($baseBlockchain, $contractAddress, $data);
            if ($wcMatch) {
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
            }
        }

        $timestamp = $data['timestamp'] ?? $data['date'] ?? null;
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
                return;
            }
            throw $e;
        }

        $amount = (float) $amountStr;
        if ($amount <= 0) {
            Log::info('Tatum webhook: non-positive amount', ['txId' => $txId, 'amount' => $amountStr]);

            return;
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
            $baseBlockchain
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $masterAddressesLower
     */
    protected function processAccountIdIncoming(
        array $data,
        VirtualAccount $va,
        string $subscriptionType,
        string $txId,
        array $masterAddressesLower
    ): void {
        $from = $this->resolveCounterpartyAddress($data);
        if ($from && $this->isMasterAddress($from, $masterAddressesLower)) {
            Log::info('Tatum webhook ignored (accountId path, sender is master)', ['txId' => $txId]);

            return;
        }

        $amountStr = (string) ($data['amount'] ?? '0');
        $amount = (float) $amountStr;
        if ($amount <= 0) {
            return;
        }

        $currency = $va->currency;
        $baseBlockchain = DepositAddressService::normalizeBlockchain((string) $va->blockchain);

        try {
            TatumWebhookResponse::query()->create([
                'account_id' => $va->account_id,
                'subscription_type' => $subscriptionType ?: 'ACCOUNT_INCOMING',
                'amount' => is_numeric($amountStr) ? $amountStr : 0,
                'reference' => isset($data['reference']) ? (string) $data['reference'] : null,
                'currency' => $currency,
                'tx_id' => $txId,
                'block_height' => isset($data['blockNumber']) ? (int) $data['blockNumber'] : (isset($data['blockHeight']) ? (int) $data['blockHeight'] : null),
                'block_hash' => isset($data['blockHash']) ? (string) $data['blockHash'] : null,
                'from_address' => $from,
                'to_address' => (string) ($data['address'] ?? $data['to'] ?? ''),
                'transaction_date' => now(),
                'index' => isset($data['logIndex']) ? (int) $data['logIndex'] : null,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            $msg = strtolower($e->getMessage());
            if (str_contains($msg, 'unique') || str_contains($msg, 'duplicate')) {
                return;
            }
            throw $e;
        }

        $this->creditVirtualAccountAndLedger(
            $va,
            $va->user_id,
            $currency,
            $amount,
            $txId,
            $subscriptionType,
            (string) $from,
            (string) ($data['address'] ?? $data['to'] ?? ''),
            $baseBlockchain
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function resolveCounterpartyAddress(array $data): ?string
    {
        $candidates = [
            $data['counterAddress'] ?? null,
            $data['counter_address'] ?? null,
            $data['from'] ?? null,
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
     * @param  array<int, string>  $masterAddressesLower
     */
    protected function isMasterAddress(string $address, array $masterAddressesLower): bool
    {
        $lower = strtolower(trim($address));

        return in_array($lower, $masterAddressesLower, true);
    }

    /**
     * @param  mixed  $contractAddress
     * @param  array<string, mixed>  $data
     */
    protected function resolveFungibleWalletCurrency(string $baseBlockchain, $contractAddress, array $data): ?WalletCurrency
    {
        $chainLower = strtolower($baseBlockchain);

        if (is_string($contractAddress) && trim($contractAddress) !== '' && strtoupper($contractAddress) !== 'ETH') {
            $contract = trim($contractAddress);
            $wc = WalletCurrency::query()
                ->whereRaw('LOWER(blockchain) = ?', [$chainLower])
                ->whereNotNull('contract_address')
                ->get()
                ->first(function (WalletCurrency $w) use ($contract) {
                    return strcasecmp((string) $w->contract_address, $contract) === 0;
                });
            if ($wc) {
                return $wc;
            }
        }

        $symbolHints = array_filter([
            is_string($contractAddress) ? $contractAddress : null,
            $data['currency'] ?? null,
            $data['symbol'] ?? null,
        ], fn ($v) => is_string($v) && $v !== '');

        foreach ($symbolHints as $hint) {
            $u = strtoupper(str_replace([' ', '-'], '_', (string) $hint));
            if ($u === 'USDT_TRON' || ($chainLower === 'tron' && str_contains($u, 'USDT'))) {
                $tronUsdt = config('tatum.contracts.tron.USDT');
                if ($tronUsdt) {
                    $wc = WalletCurrency::query()
                        ->whereRaw('LOWER(blockchain) = ?', ['tron'])
                        ->where('currency', 'USDT')
                        ->whereNotNull('contract_address')
                        ->first();
                    if ($wc) {
                        return $wc;
                    }
                }
            }
        }

        return null;
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
        string $baseBlockchain
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
            $baseBlockchain
        ) {
            $account = VirtualAccount::query()
                ->whereKey($virtualAccount->id)
                ->lockForUpdate()
                ->firstOrFail();

            $currentAvailable = (float) ($account->available_balance ?? '0');
            $currentAccount = (float) ($account->account_balance ?? '0');
            $newAvailable = $currentAvailable + $amount;
            $newAccount = $currentAccount + $amount;

            $account->available_balance = (string) $newAvailable;
            $account->account_balance = (string) $newAccount;
            $account->save();

            $walletCurrency = WalletCurrency::query()
                ->where('currency', $currency)
                ->whereRaw('LOWER(blockchain) = ?', [strtolower($account->blockchain)])
                ->with('exchangeRate')
                ->first();

            $rate = $walletCurrency ? $walletCurrency->usdPerUnitForDisplay() : 1.0;
            $amountUsd = $amount * $rate;
            $amountNgn = $amountUsd * (float) config('crypto.ngn_per_usd', CryptoService::DEFAULT_EXCHANGE_RATE);

            Transaction::query()->create([
                'user_id' => $userId,
                'transaction_id' => Transaction::generateTransactionId(),
                'type' => 'crypto_deposit',
                'category' => 'on_chain_receive',
                'status' => 'completed',
                'currency' => $currency,
                'amount' => $amount,
                'fee' => 0,
                'total_amount' => $amount,
                'reference' => Transaction::generateTransactionId(),
                'description' => "On-chain deposit {$amount} {$currency}",
                'metadata' => [
                    'blockchain' => $account->blockchain,
                    'network' => $baseBlockchain,
                    'tx_hash' => $txId,
                    'from_address' => $fromAddress,
                    'to_address' => $toAddress,
                    'subscription_type' => $subscriptionType,
                    'virtual_account_id' => $account->id,
                    'amount_usd' => round($amountUsd, 8),
                    'amount_ngn' => round($amountNgn, 2),
                ],
                'completed_at' => now(),
            ]);
        });
    }
}
