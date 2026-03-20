<?php

namespace App\Jobs;

use App\Models\CryptoDepositAddress;
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

        $isAddressWebhook = in_array($subscriptionType, [
            'ADDRESS_EVENT',
            'INCOMING_NATIVE_TX',
            'INCOMING_FUNGIBLE_TX',
        ], true);

        if (! $isAddressWebhook) {
            throw new \RuntimeException('Unsupported subscription type: '.$subscriptionType);
        }

        $webhookAddress = $data['address'] ?? $data['to'] ?? null;
        $counterAddress = $data['counterAddress'] ?? $data['from'] ?? null;

        if (! $counterAddress) {
            Log::info('Tatum webhook ignored (no counterAddress / sender)', ['txId' => $txId]);

            return;
        }

        if (! $webhookAddress) {
            throw new \RuntimeException('Missing receiving address in webhook payload');
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
        $isFungible = $subscriptionType === 'INCOMING_FUNGIBLE_TX' && is_string($contractAddress) && $contractAddress !== ''
            && strtoupper($contractAddress) !== 'ETH';

        $virtualAccount = $depositRow->virtualAccount;
        $detectedCurrency = $virtualAccount->currency;

        if ($isFungible && is_string($contractAddress)) {
            $walletCurrency = WalletCurrency::query()
                ->whereRaw('LOWER(blockchain) = ?', [strtolower($baseBlockchain)])
                ->whereNotNull('contract_address')
                ->get()
                ->first(function (WalletCurrency $wc) use ($contractAddress) {
                    return strcasecmp((string) $wc->contract_address, $contractAddress) === 0;
                });

            if ($walletCurrency) {
                $detectedCurrency = $walletCurrency->currency;
                $betterVa = VirtualAccount::query()
                    ->where('user_id', $userId)
                    ->where('currency', $walletCurrency->currency)
                    ->whereRaw('LOWER(blockchain) = ?', [strtolower($walletCurrency->blockchain)])
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
                'from_address' => is_string($counterAddress) ? $counterAddress : null,
                'to_address' => (string) $webhookAddress,
                'transaction_date' => $transactionDate,
                'index' => isset($data['logIndex']) ? (int) $data['logIndex'] : null,
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
            is_string($counterAddress) ? $counterAddress : '',
            (string) $webhookAddress,
            $baseBlockchain
        );
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
                ->first();

            $rate = $walletCurrency ? (float) ($walletCurrency->rate ?? 0) : 1.0;
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
                    'amount_usd' => round($amountUsd, 8),
                    'amount_ngn' => round($amountNgn, 2),
                ],
                'completed_at' => now(),
            ]);
        });
    }
}
