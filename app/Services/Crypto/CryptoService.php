<?php

namespace App\Services\Crypto;

use App\Models\FiatWallet;
use App\Models\MasterWallet;
use App\Models\MasterWalletTransaction;
use App\Models\PlatformRate;
use App\Models\Transaction;
use App\Models\VirtualAccount;
use App\Models\WalletCurrency;
use App\Services\Platform\PlatformRateResolver;
use App\Services\Tatum\DepositAddressService;
use App\Services\Tatum\TatumOutboundTxService;
use App\Services\Transaction\TransactionService;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\DB;

class CryptoService
{
    protected CryptoWalletService $cryptoWalletService;

    protected TransactionService $transactionService;

    protected WalletService $walletService;

    /** Default send/withdraw processing fee (USD) when no admin `platform_rates` row exists. */
    const SEND_FEE_USD = 3.0;

    /** @deprecated Use config('crypto.ngn_per_usd') */
    const DEFAULT_EXCHANGE_RATE = 1500.0;

    protected function ngnPerUsd(): float
    {
        return (float) config('crypto.ngn_per_usd', self::DEFAULT_EXCHANGE_RATE);
    }

    public function __construct(
        CryptoWalletService $cryptoWalletService,
        TransactionService $transactionService,
        WalletService $walletService,
        protected DepositAddressService $depositAddressService,
        protected TatumOutboundTxService $tatumOutbound,
        protected PlatformRateResolver $platformRates
    ) {
        $this->cryptoWalletService = $cryptoWalletService;
        $this->transactionService = $transactionService;
        $this->walletService = $walletService;
    }

    /**
     * On-chain receive / send-out processing: `fee_usd` (flat) + `percentage_fee` of the **USD notional**
     * of the crypto amount. Crypto admin rows use USD only (legacy `fixed_fee_ngn` is ignored here).
     *
     * @return array{fee_usd: float, fee_crypto: float, net_crypto: float, gross_crypto: float}
     */
    protected function computeUsdBasedCryptoFee(?PlatformRate $row, float $grossCrypto, float $usdPerUnit): array
    {
        $usdPer = max((float) $usdPerUnit, 0.0000000001);
        $gross = max(0.0, (float) $grossCrypto);
        $amountUsd = $gross * $usdPer;
        $feeUsd = 0.0;
        if ($row) {
            $fixed = $row->fee_usd !== null ? (float) $row->fee_usd : 0.0;
            $pct = $row->percentage_fee !== null ? (float) $row->percentage_fee / 100.0 : 0.0;
            $feeUsd = max(0.0, $fixed + $amountUsd * $pct);
        }
        $feeCrypto = $feeUsd / $usdPer;
        if ($feeCrypto > $gross) {
            $feeCrypto = $gross;
            $feeUsd = $feeCrypto * $usdPer;
        }
        $net = max(0.0, $gross - $feeCrypto);

        return [
            'fee_usd' => round($feeUsd, 8),
            'fee_crypto' => round($feeCrypto, 12),
            'net_crypto' => round($net, 12),
            'gross_crypto' => $gross,
        ];
    }

    /**
     * Apply admin deposit processing fee to an on-chain incoming amount (gross → net credit).
     *
     * @return array{fee_usd: float, fee_crypto: float, net_crypto: float, gross_crypto: float}
     */
    public function computeOnChainDepositSettlement(float $grossCrypto, string $currency, string $blockchain): array
    {
        $wc = WalletCurrency::findActiveForCrypto($currency, $blockchain);
        $usdPer = $wc ? (float) $wc->usdPerUnitForDisplay() : 1.0;
        $row = $this->platformRates->findCrypto('deposit', $currency, $blockchain);

        return $this->computeUsdBasedCryptoFee($row, $grossCrypto, $usdPer);
    }

    /**
     * Quote send/withdraw processing fee (for UI). Uses admin withdrawal rate or {@see SEND_FEE_USD} if unset.
     *
     * @return array{success: bool, data?: array<string, mixed>, message?: string}
     */
    public function previewSendProcessingFee(string $currency, string $blockchain, float $amountCrypto): array
    {
        if ($amountCrypto < 0) {
            return ['success' => false, 'message' => 'Invalid amount'];
        }
        $wc = WalletCurrency::findActiveForCrypto($currency, $blockchain);
        $rate = $wc ? (float) $wc->usdPerUnitForSell() : 1.0;
        if ($rate <= 0) {
            $rate = 1.0;
        }
        $row = $this->platformRates->findCryptoSendOrWithdrawal($currency, $blockchain);
        if ($row === null) {
            $feeUsd = self::SEND_FEE_USD;
            $feeCrypto = $feeUsd / $rate;

            return [
                'success' => true,
                'data' => [
                    'fee_usd' => round($feeUsd, 8),
                    'fee_crypto' => round($feeCrypto, 12),
                    'total_crypto_debit' => round($amountCrypto + $feeCrypto, 12),
                    'send_amount_crypto' => $amountCrypto,
                    'uses_default_fee' => true,
                ],
            ];
        }
        $pack = $this->computeUsdBasedCryptoFee($row, $amountCrypto, $rate);

        return [
            'success' => true,
            'data' => [
                'fee_usd' => $pack['fee_usd'],
                'fee_crypto' => $pack['fee_crypto'],
                'total_crypto_debit' => round($amountCrypto + $pack['fee_crypto'], 12),
                'send_amount_crypto' => $amountCrypto,
                'uses_default_fee' => false,
            ],
        ];
    }

    /**
     * Whether this `wallet_currencies.currency` value is USDT on any chain (ERC-20, BSC, TRON, …).
     */
    protected function isUsdtFamilyLedgerCurrency(?string $currency): bool
    {
        if ($currency === null || $currency === '') {
            return false;
        }

        $c = strtoupper(trim($currency));

        return $c === 'USDT' || str_starts_with($c, 'USDT_');
    }

    /**
     * Whether this ledger `currency` is USDC on any chain (ERC-20, BEP-20, …).
     */
    protected function isUsdcFamilyLedgerCurrency(?string $currency): bool
    {
        if ($currency === null || $currency === '') {
            return false;
        }

        $c = strtoupper(trim($currency));

        return $c === 'USDC' || str_starts_with($c, 'USDC_');
    }

    /**
     * Get USDC networks (Ethereum + BSC by default; extend query if more USDC_* rows are added).
     */
    public function getUsdcBlockchains(): array
    {
        $rows = WalletCurrency::query()
            ->where('is_active', true)
            ->where(function ($q) {
                $q->where('currency', 'USDC')
                    ->orWhere('currency', 'like', 'USDC\_%');
            })
            ->orderBy('id')
            ->get();

        return $rows->map(function (WalletCurrency $currency) {
            return [
                'id' => $currency->id,
                'blockchain' => $currency->blockchain,
                'blockchain_name' => $currency->blockchain_name,
                'network' => $currency->blockchain,
                'currency' => 'USDC',
                'symbol' => 'USDC',
                'contract_address' => $currency->contract_address,
                'decimals' => $currency->decimals,
                'is_token' => $currency->is_token,
                'crediting_time' => '1 min',
            ];
        })->toArray();
    }

    /**
     * Map user-facing USDC + chain to `virtual_accounts.currency` (USDC vs USDC_BSC).
     */
    protected function resolveUsdcLedgerCurrency(string $blockchainInput): string
    {
        $blockchain = DepositAddressService::normalizeBlockchain($blockchainInput);

        $row = WalletCurrency::query()
            ->where('is_active', true)
            ->whereRaw('LOWER(blockchain) = ?', [strtolower($blockchain)])
            ->where(function ($q) {
                $q->where('currency', 'USDC')
                    ->orWhere('currency', 'like', 'USDC\_%');
            })
            ->first();

        return $row ? strtoupper((string) $row->currency) : 'USDC';
    }

    /**
     * Get USDT blockchains
     *
     * ERC-20 uses `currency` = USDT; BSC/TRON use USDT_BSC / USDT_TRON. Do not rely on `symbol` (may be null in DB).
     */
    public function getUsdtBlockchains(): array
    {
        $usdtCurrencies = WalletCurrency::query()
            ->where('is_active', true)
            ->where(function ($q) {
                $q->where('currency', 'USDT')
                    ->orWhereIn('currency', ['USDT_BSC', 'USDT_TRON', 'USDT_SOL', 'USDT_POLYGON'])
                    ->orWhere('currency', 'like', 'USDT\_%');
            })
            ->orderBy('id')
            ->get();

        return $usdtCurrencies->map(function ($currency) {
            return [
                'id' => $currency->id,
                'blockchain' => $currency->blockchain,
                'blockchain_name' => $currency->blockchain_name,
                'network' => $currency->blockchain,
                'currency' => 'USDT',
                'symbol' => 'USDT',
                'contract_address' => $currency->contract_address,
                'decimals' => $currency->decimals,
                'is_token' => $currency->is_token,
                'crediting_time' => '1 min', // Default crediting time
            ];
        })->toArray();
    }

    /**
     * Map user-facing USDT + chain to ledger row in `wallet_currencies` / `virtual_accounts.currency`.
     */
    protected function resolveUsdtLedgerCurrency(string $blockchainInput): string
    {
        $blockchain = DepositAddressService::normalizeBlockchain($blockchainInput);

        $row = WalletCurrency::query()
            ->where('is_active', true)
            ->whereRaw('LOWER(blockchain) = ?', [strtolower($blockchain)])
            ->where(function ($q) {
                $q->where('currency', 'USDT')
                    ->orWhereIn('currency', ['USDT_BSC', 'USDT_TRON', 'USDT_SOL', 'USDT_POLYGON'])
                    ->orWhere('currency', 'like', 'USDT\_%');
            })
            ->first();

        return $row ? strtoupper((string) $row->currency) : 'USDT';
    }

    /**
     * Get virtual accounts (grouped - USDT shown as one)
     */
    public function getVirtualAccountsGrouped(int $userId): array
    {
        $accounts = VirtualAccount::where('user_id', $userId)
            ->where('active', true)
            ->with('walletCurrency.exchangeRate')
            ->get();

        $grouped = [];
        $usdtAccounts = [];
        $usdcAccounts = [];

        foreach ($accounts as $account) {
            if ($this->isUsdtFamilyLedgerCurrency($account->currency)) {
                $usdtAccounts[] = $account;
            } elseif ($this->isUsdcFamilyLedgerCurrency($account->currency)) {
                $usdcAccounts[] = $account;
            } else {
                $grouped[] = $this->formatAccount($account);
            }
        }

        // Group USDT accounts as one
        if (! empty($usdtAccounts)) {
            $totalUsdtBalance = 0;
            $totalUsdtUsdValue = 0;
            $blockchains = [];

            foreach ($usdtAccounts as $account) {
                $balance = (float) $account->available_balance;
                $totalUsdtBalance += $balance;

                if ($account->walletCurrency) {
                    $rate = $account->walletCurrency->usdPerUnitForDisplay();
                    $totalUsdtUsdValue += $balance * $rate;

                    $blockchains[] = [
                        'blockchain' => $account->blockchain,
                        'blockchain_name' => $account->walletCurrency->blockchain_name ?? $account->blockchain,
                        'balance' => $balance,
                        'account_id' => $account->id,
                    ];
                }
            }

            $grouped[] = [
                'id' => 'usdt_grouped',
                'currency' => 'USDT',
                'symbol' => 'USDT',
                'name' => 'Tether',
                'balance' => $totalUsdtBalance,
                'usd_value' => $totalUsdtUsdValue,
                'rate' => $totalUsdtBalance > 0 ? $totalUsdtUsdValue / $totalUsdtBalance : 0,
                'blockchains' => $blockchains,
                'is_grouped' => true,
            ];
        }

        if (! empty($usdcAccounts)) {
            $totalUsdcBalance = 0;
            $totalUsdcUsdValue = 0;
            $usdcBlockchains = [];

            foreach ($usdcAccounts as $account) {
                $balance = (float) $account->available_balance;
                $totalUsdcBalance += $balance;

                if ($account->walletCurrency) {
                    $rate = $account->walletCurrency->usdPerUnitForDisplay();
                    $totalUsdcUsdValue += $balance * $rate;

                    $usdcBlockchains[] = [
                        'blockchain' => $account->blockchain,
                        'blockchain_name' => $account->walletCurrency->blockchain_name ?? $account->blockchain,
                        'balance' => $balance,
                        'account_id' => $account->id,
                    ];
                }
            }

            $grouped[] = [
                'id' => 'usdc_grouped',
                'currency' => 'USDC',
                'symbol' => 'USDC',
                'name' => 'USD Coin',
                'balance' => $totalUsdcBalance,
                'usd_value' => $totalUsdcUsdValue,
                'rate' => $totalUsdcBalance > 0 ? $totalUsdcUsdValue / $totalUsdcBalance : 0,
                'blockchains' => $usdcBlockchains,
                'is_grouped' => true,
            ];
        }

        return $grouped;
    }

    /**
     * Get account details by currency and blockchain
     */
    public function getAccountDetails(int $userId, string $currency, ?string $blockchain = null): ?array
    {
        $account = null;

        if ($currency === 'USDT' && $blockchain) {
            $ledger = $this->resolveUsdtLedgerCurrency($blockchain);
            $normB = DepositAddressService::normalizeBlockchain($blockchain);
            $account = VirtualAccount::where('user_id', $userId)
                ->where('currency', $ledger)
                ->whereRaw('LOWER(blockchain) = ?', [strtolower($normB)])
                ->where('active', true)
                ->with('walletCurrency.exchangeRate')
                ->first();
        } elseif ($currency === 'USDC' && $blockchain) {
            $ledger = $this->resolveUsdcLedgerCurrency($blockchain);
            $normB = DepositAddressService::normalizeBlockchain($blockchain);
            $account = VirtualAccount::where('user_id', $userId)
                ->where('currency', $ledger)
                ->whereRaw('LOWER(blockchain) = ?', [strtolower($normB)])
                ->where('active', true)
                ->with('walletCurrency.exchangeRate')
                ->first();
        } else {
            // For non-USDT or USDT without blockchain, get first matching account
            $account = VirtualAccount::where('user_id', $userId)
                ->where('currency', $currency)
                ->where('active', true)
                ->with('walletCurrency.exchangeRate')
                ->first();
        }

        if (! $account) {
            return null;
        }

        $accountData = $this->formatAccount($account);

        // Get transactions for this currency and blockchain
        $transactionQuery = Transaction::where('user_id', $userId)
            ->where('currency', $account->currency)
            ->whereIn('type', ['crypto_buy', 'crypto_sell', 'crypto_withdrawal', 'crypto_deposit']);

        // Filter by blockchain if provided
        if ($blockchain) {
            $transactionQuery->where(function ($q) use ($blockchain) {
                $q->where('metadata->blockchain', $blockchain)
                    ->orWhere('metadata->network', $blockchain);
            });
        }

        $transactions = $transactionQuery->orderBy('created_at', 'desc')
            ->limit(20)
            ->get()
            ->map(function ($transaction) {
                $metadata = $transaction->metadata ?? [];

                return [
                    'id' => $transaction->id,
                    'transaction_id' => $transaction->transaction_id,
                    'type' => $transaction->type,
                    'category' => $transaction->category,
                    'status' => $transaction->status,
                    'amount' => (float) $transaction->amount,
                    'fee' => (float) $transaction->fee,
                    'total_amount' => (float) $transaction->total_amount,
                    'reference' => $transaction->reference,
                    'description' => $transaction->description,
                    'blockchain' => $metadata['blockchain'] ?? $metadata['network'] ?? null,
                    'metadata' => $metadata,
                    'created_at' => $transaction->created_at->toISOString(),
                    'updated_at' => $transaction->updated_at->toISOString(),
                ];
            });

        $accountData['transactions'] = $transactions;

        return $accountData;
    }

    /**
     * Get deposit address for receiving crypto.
     * Uses Tatum (V3 wallet + V4 webhooks) unless config `tatum.use_mock` is true.
     */
    public function getDepositAddress(int $userId, string $currency, string $blockchain): array
    {
        $normalizedCurrency = strtoupper(trim($currency));
        $normalizedBlockchain = DepositAddressService::normalizeBlockchain($blockchain);

        $account = $this->findActiveVirtualAccount($userId, $normalizedCurrency, $normalizedBlockchain);

        if (! $account) {
            $this->cryptoWalletService->initializeUserCryptoWallets($userId);
            $account = $this->findActiveVirtualAccount($userId, $normalizedCurrency, $normalizedBlockchain);
        }

        if (! $account) {
            return [
                'success' => false,
                'message' => 'Account not found',
            ];
        }

        if (config('tatum.use_mock')) {
            $depositAddress = $this->generateDepositAddress($account);
        } else {
            try {
                $depositAddress = $this->depositAddressService->ensureDepositAddressForVirtualAccount($account);
            } catch (\Throwable $e) {
                return [
                    'success' => false,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return [
            'success' => true,
            'data' => [
                'currency' => $normalizedCurrency,
                'blockchain' => $normalizedBlockchain,
                'network' => $normalizedBlockchain,
                'deposit_address' => $depositAddress,
                'qr_code' => $this->generateQrCode($depositAddress),
                'account_id' => $account->id,
            ],
        ];
    }

    /**
     * Get exchange rate for buy/sell.
     *
     * @param  string|null  $blockchain  Required when multiple `wallet_currencies` rows exist for the same `currency` (e.g. USDT on tron vs ethereum).
     */
    public function getExchangeRate(string $fromCurrency, string $toCurrency, float $amount, ?string $blockchain = null): array
    {
        $fromCurrency = strtoupper(trim($fromCurrency));
        $toCurrency = strtoupper(trim($toCurrency));

        $cryptoCurrency = $fromCurrency !== 'NGN' ? $fromCurrency : $toCurrency;

        if ($cryptoCurrency === 'NGN') {
            return [
                'success' => false,
                'message' => 'Invalid currency pair',
            ];
        }

        $walletCurrency = WalletCurrency::findActiveForCrypto($cryptoCurrency, $blockchain);

        if (! $walletCurrency) {
            $msg = WalletCurrency::query()
                ->where('currency', $cryptoCurrency)
                ->where('is_active', true)
                ->count() > 1
                ? 'blockchain is required for this currency (multiple networks exist).'
                : 'Currency not found';

            return [
                'success' => false,
                'message' => $msg,
            ];
        }

        // Buy path: NGN → crypto uses rate_buy; sell path: crypto → NGN uses rate_sell (`crypto_exchange_rates`).
        $isBuyWithNgn = $fromCurrency === 'NGN' && $toCurrency !== 'NGN';
        $rateUsdPerCrypto = $isBuyWithNgn
            ? $walletCurrency->usdPerUnitForBuy()
            : ($fromCurrency !== 'NGN' && $toCurrency === 'NGN'
                ? $walletCurrency->usdPerUnitForSell()
                : (float) ($walletCurrency->rate ?? 0));

        $ngnPerUsd = $this->ngnPerUsd();

        // Admin overrides for buy/sell: `exchange_rate_ngn_per_usd` = NGN charged (buy) or paid (sell) per 1 whole crypto unit.
        if ($isBuyWithNgn) {
            $buyRow = $this->platformRates->findCrypto('buy', $cryptoCurrency, $blockchain);
            if ($buyRow && $buyRow->exchange_rate_ngn_per_usd !== null && (float) $buyRow->exchange_rate_ngn_per_usd > 0) {
                $ngnPerCrypto = (float) $buyRow->exchange_rate_ngn_per_usd;
                $rateUsdPerCrypto = $ngnPerCrypto / max($ngnPerUsd, 0.0000001);
            }
        } elseif ($fromCurrency !== 'NGN' && $toCurrency === 'NGN') {
            $sellRow = $this->platformRates->findCrypto('sell', $cryptoCurrency, $blockchain);
            if ($sellRow && $sellRow->exchange_rate_ngn_per_usd !== null && (float) $sellRow->exchange_rate_ngn_per_usd > 0) {
                $ngnPerCrypto = (float) $sellRow->exchange_rate_ngn_per_usd;
                $rateUsdPerCrypto = $ngnPerCrypto / max($ngnPerUsd, 0.0000001);
            }
        }

        if ($rateUsdPerCrypto <= 0) {
            return [
                'success' => false,
                'message' => 'Exchange rate not configured for this asset',
            ];
        }

        $exchangeRate = 1.0;

        if ($fromCurrency === 'NGN' && $toCurrency !== 'NGN') {
            $ngnToUsd = 1 / $ngnPerUsd;
            $exchangeRate = $ngnToUsd / $rateUsdPerCrypto;
            $cryptoAmount = $amount * $exchangeRate;
            $fiatAmount = $amount;
        } elseif ($fromCurrency !== 'NGN' && $toCurrency === 'NGN') {
            $exchangeRate = $rateUsdPerCrypto * $ngnPerUsd;
            $cryptoAmount = $amount;
            $fiatAmount = $amount * $exchangeRate;
        } else {
            $cryptoAmount = $amount;
            $fiatAmount = $amount;
        }

        return [
            'success' => true,
            'data' => [
                'from_currency' => $fromCurrency,
                'to_currency' => $toCurrency,
                'blockchain' => $walletCurrency->blockchain,
                'amount' => $amount,
                'exchange_rate' => $exchangeRate,
                'crypto_amount' => $cryptoAmount,
                'fiat_amount' => $fiatAmount,
                'rate' => $rateUsdPerCrypto,
                'rate_buy' => $walletCurrency->usdPerUnitForBuy(),
                'rate_sell' => $walletCurrency->usdPerUnitForSell(),
            ],
        ];
    }

    /**
     * Preview buy crypto transaction
     */
    public function previewBuyCrypto(int $userId, array $data): array
    {
        $currency = $data['currency'];
        $blockchain = $data['blockchain'];
        $amount = (float) $data['amount'];
        $paymentMethod = $data['payment_method'] ?? 'naira';

        $rateData = $this->getExchangeRate('NGN', $currency, $amount, $blockchain);
        if (! $rateData['success']) {
            return $rateData;
        }

        $existingVa = VirtualAccount::where('user_id', $userId)
            ->where('currency', $currency)
            ->where('blockchain', $blockchain)
            ->where('active', true)
            ->first();
        if ($existingVa && $existingVa->frozen) {
            return [
                'success' => false,
                'message' => 'This crypto wallet is frozen.',
            ];
        }

        $ngnWallet = FiatWallet::where('user_id', $userId)
            ->where('currency', 'NGN')
            ->where('country_code', 'NG')
            ->first();
        if ($ngnWallet && ! $ngnWallet->is_active) {
            return [
                'success' => false,
                'message' => 'Your Naira wallet is inactive.',
            ];
        }

        $cryptoAmount = $rateData['data']['crypto_amount'];
        $exchangeRate = $rateData['data']['exchange_rate'];

        return [
            'success' => true,
            'data' => [
                'currency' => $currency,
                'blockchain' => $blockchain,
                'network' => $blockchain,
                'amount' => $amount,
                'payment_method' => $paymentMethod,
                'crypto_amount' => $cryptoAmount,
                'fee_percent' => 0.0,
                'fee_in_crypto' => 0.0,
                'fee_in_payment_currency' => 0.0,
                'total_crypto_amount' => $cryptoAmount,
                'total_amount' => $amount,
                'exchange_rate' => $exchangeRate,
                'rate' => $rateData['data']['rate'],
            ],
        ];
    }

    /**
     * Confirm buy crypto transaction
     */
    public function confirmBuyCrypto(int $userId, array $data): array
    {
        $preview = $this->previewBuyCrypto($userId, $data);
        if (! $preview['success']) {
            return $preview;
        }

        $previewData = $preview['data'];
        $currency = $data['currency'];
        $blockchain = $data['blockchain'];
        $paymentMethod = $data['payment_method'] ?? 'naira';

        return DB::transaction(function () use ($userId, $currency, $blockchain, $previewData, $paymentMethod) {
            // Deduct from Naira wallet with lock (buy always uses Naira)
            $fiatWallet = FiatWallet::where('user_id', $userId)
                ->where('currency', 'NGN')
                ->where('country_code', 'NG')
                ->lockForUpdate()
                ->first();

            if (! $fiatWallet) {
                // Create wallet if doesn't exist
                $fiatWallet = $this->walletService->createFiatWallet($userId, 'NGN', 'NG');
                // Reload with lock
                $fiatWallet = FiatWallet::where('id', $fiatWallet->id)
                    ->lockForUpdate()
                    ->first();
            }

            if (! $fiatWallet->is_active) {
                return [
                    'success' => false,
                    'message' => 'Your Naira wallet is inactive.',
                ];
            }

            if ($fiatWallet->balance < $previewData['amount']) {
                return [
                    'success' => false,
                    'message' => 'Insufficient balance',
                ];
            }

            // Credit crypto wallet (lock for update to prevent race conditions)
            $account = VirtualAccount::where('user_id', $userId)
                ->where('currency', $currency)
                ->where('blockchain', $blockchain)
                ->where('active', true)
                ->lockForUpdate()
                ->first();

            if (! $account) {
                // Try to initialize all wallets first
                $this->cryptoWalletService->initializeUserCryptoWallets($userId);

                // Try to find account again
                $account = VirtualAccount::where('user_id', $userId)
                    ->where('currency', $currency)
                    ->where('blockchain', $blockchain)
                    ->where('active', true)
                    ->lockForUpdate()
                    ->first();

                // If still not found, create it directly
                if (! $account) {
                    $walletCurrency = WalletCurrency::findActiveForCrypto($currency, $blockchain);

                    $accountId = strtoupper($blockchain).'_'.strtoupper($currency).'_'.$userId.'_'.time().'_'.\Illuminate\Support\Str::random(8);

                    $account = VirtualAccount::create([
                        'user_id' => $userId,
                        'currency_id' => $walletCurrency?->id,
                        'blockchain' => $blockchain,
                        'currency' => $currency,
                        'customer_id' => 'CUST_'.$userId,
                        'account_id' => $accountId,
                        'account_code' => \Illuminate\Support\Str::random(10),
                        'active' => true,
                        'frozen' => false,
                        'account_balance' => '0',
                        'available_balance' => '0',
                        'accounting_currency' => $currency,
                    ]);

                    // Reload with lock for update
                    $account = VirtualAccount::where('id', $account->id)
                        ->lockForUpdate()
                        ->first();
                }
            }

            if ($account && $account->frozen) {
                return [
                    'success' => false,
                    'message' => 'This crypto wallet is frozen.',
                ];
            }

            $fiatWallet->decrement('balance', $previewData['amount']);

            if ($account) {
                // Manually update balances since they're stored as strings
                $currentAvailableBalance = (float) ($account->available_balance ?? '0');
                $currentAccountBalance = (float) ($account->account_balance ?? '0');
                $newAvailableBalance = $currentAvailableBalance + $previewData['crypto_amount'];
                $newAccountBalance = $currentAccountBalance + $previewData['crypto_amount'];

                $account->available_balance = (string) $newAvailableBalance;
                $account->account_balance = (string) $newAccountBalance;
                $account->save();

                // Refresh account with relationships
                $account->refresh();
                $account->load('walletCurrency.exchangeRate');
            }

            $walletCurrencyMeta = WalletCurrency::findActiveForCrypto($currency, $blockchain);
            $ngnPerUsd = $this->ngnPerUsd();
            $refUsdBuy = $walletCurrencyMeta ? (float) $walletCurrencyMeta->usdPerUnitForBuy() : 0.0;
            $referenceNgnPerCrypto = $refUsdBuy > 0 ? $refUsdBuy * $ngnPerUsd : null;
            $cryptoAmt = (float) $previewData['crypto_amount'];
            $paymentNgn = (float) $previewData['amount'];
            $appliedNgnPerCrypto = $cryptoAmt > 0 ? $paymentNgn / $cryptoAmt : null;

            // Create transaction
            $transaction = Transaction::create([
                'user_id' => $userId,
                'transaction_id' => Transaction::generateTransactionId(),
                'type' => 'crypto_buy',
                'category' => 'crypto_purchase',
                'status' => 'completed',
                'currency' => $currency,
                'amount' => $previewData['crypto_amount'],
                'fee' => 0,
                'total_amount' => $previewData['crypto_amount'],
                'reference' => 'BUY'.strtoupper(substr(md5(uniqid(rand(), true)), 0, 12)),
                'description' => "Buy {$previewData['crypto_amount']} {$currency}",
                'metadata' => [
                    'blockchain' => $blockchain,
                    'network' => $blockchain,
                    'blockchain_name' => $walletCurrencyMeta?->blockchain_name,
                    'payment_method' => $paymentMethod,
                    'payment_amount' => $previewData['amount'],
                    'payment_currency' => $paymentMethod === 'naira' ? 'NGN' : 'USD',
                    'exchange_rate' => $previewData['exchange_rate'],
                    'reference_ngn_per_crypto' => $referenceNgnPerCrypto !== null ? round($referenceNgnPerCrypto, 8) : null,
                    'applied_ngn_per_crypto' => $appliedNgnPerCrypto !== null ? round($appliedNgnPerCrypto, 8) : null,
                ],
                'completed_at' => now(),
            ]);

            return [
                'success' => true,
                'message' => 'Crypto purchase completed successfully',
                'data' => [
                    'transaction' => $transaction,
                    'account' => $account ? $this->formatAccount($account->load('walletCurrency.exchangeRate')) : null,
                    'fiat_wallet' => isset($fiatWallet) ? $fiatWallet->fresh() : null,
                ],
            ];
        });
    }

    /**
     * Preview sell crypto transaction
     */
    public function previewSellCrypto(int $userId, array $data): array
    {
        $currency = $data['currency'];
        $blockchain = $data['blockchain'];
        $amount = (float) $data['amount']; // Amount in crypto

        // Validate amount
        if ($amount <= 0) {
            return [
                'success' => false,
                'message' => 'Amount must be greater than zero',
            ];
        }

        // Get account
        $account = VirtualAccount::where('user_id', $userId)
            ->where('currency', $currency)
            ->where('blockchain', $blockchain)
            ->where('active', true)
            ->with('walletCurrency.exchangeRate')
            ->first();

        if (! $account) {
            return [
                'success' => false,
                'message' => 'Account not found',
            ];
        }

        if ($account->frozen) {
            return [
                'success' => false,
                'message' => 'This crypto wallet is frozen.',
            ];
        }

        $ngnWallet = FiatWallet::where('user_id', $userId)
            ->where('currency', 'NGN')
            ->where('country_code', 'NG')
            ->first();
        if ($ngnWallet && ! $ngnWallet->is_active) {
            return [
                'success' => false,
                'message' => 'Your Naira wallet is inactive.',
            ];
        }

        // Note: Balance check here is for preview only
        // Final check will be done in confirm with lockForUpdate
        $currentBalance = (float) ($account->available_balance ?? '0');
        if ($currentBalance < $amount) {
            return [
                'success' => false,
                'message' => 'Insufficient balance',
            ];
        }

        $rateData = $this->getExchangeRate($currency, 'NGN', $amount, $blockchain);
        if (! $rateData['success']) {
            return $rateData;
        }

        $ngnAmount = $rateData['data']['fiat_amount'];
        $exchangeRate = $rateData['data']['exchange_rate'];

        return [
            'success' => true,
            'data' => [
                'currency' => $currency,
                'blockchain' => $blockchain,
                'network' => $blockchain,
                'crypto_amount' => $amount,
                'fee_percent' => 0.0,
                'fee_in_crypto' => 0.0,
                'fee_in_ngn' => 0.0,
                'total_crypto_amount' => $amount,
                'ngn_amount' => $ngnAmount,
                'amount_to_receive' => $ngnAmount,
                'exchange_rate' => $exchangeRate,
                'rate' => $rateData['data']['rate'],
            ],
        ];
    }

    /**
     * Confirm sell crypto transaction
     */
    public function confirmSellCrypto(int $userId, array $data): array
    {
        $preview = $this->previewSellCrypto($userId, $data);
        if (! $preview['success']) {
            return $preview;
        }

        $previewData = $preview['data'];
        $currency = $data['currency'];
        $blockchain = $data['blockchain'];

        return DB::transaction(function () use ($userId, $currency, $blockchain, $previewData) {
            // Debit crypto wallet with lock
            $account = VirtualAccount::where('user_id', $userId)
                ->where('currency', $currency)
                ->where('blockchain', $blockchain)
                ->where('active', true)
                ->lockForUpdate()
                ->first();

            if (! $account) {
                return [
                    'success' => false,
                    'message' => 'Account not found',
                ];
            }

            if ($account->frozen) {
                return [
                    'success' => false,
                    'message' => 'This crypto wallet is frozen.',
                ];
            }

            // Check balance inside transaction with lock
            $currentAvailableBalance = (float) ($account->available_balance ?? '0');
            if ($currentAvailableBalance < $previewData['crypto_amount']) {
                return [
                    'success' => false,
                    'message' => 'Insufficient balance',
                ];
            }

            $fiatWallet = FiatWallet::where('user_id', $userId)
                ->where('currency', 'NGN')
                ->where('country_code', 'NG')
                ->lockForUpdate()
                ->first();

            if (! $fiatWallet) {
                $fiatWallet = $this->walletService->createFiatWallet($userId, 'NGN', 'NG');
                $fiatWallet = FiatWallet::where('id', $fiatWallet->id)->lockForUpdate()->first();
            }

            if (! $fiatWallet->is_active) {
                return [
                    'success' => false,
                    'message' => 'Your Naira wallet is inactive.',
                ];
            }

            // Manually update balances since they're stored as strings
            $currentAccountBalance = (float) ($account->account_balance ?? '0');
            $newAvailableBalance = $currentAvailableBalance - $previewData['crypto_amount'];
            $newAccountBalance = $currentAccountBalance - $previewData['crypto_amount'];

            $account->available_balance = (string) $newAvailableBalance;
            $account->account_balance = (string) $newAccountBalance;
            $account->save();

            // Refresh account with relationships
            $account->refresh();
            $account->load('walletCurrency.exchangeRate');

            $fiatWallet->increment('balance', $previewData['amount_to_receive']);

            $walletCurrencyMeta = WalletCurrency::findActiveForCrypto($currency, $blockchain);
            $ngnPerUsd = $this->ngnPerUsd();
            $refUsdSell = $walletCurrencyMeta ? (float) $walletCurrencyMeta->usdPerUnitForSell() : 0.0;
            $referenceNgnPerCrypto = $refUsdSell > 0 ? $refUsdSell * $ngnPerUsd : null;
            $cryptoAmt = (float) $previewData['crypto_amount'];
            $ngnRecv = (float) $previewData['ngn_amount'];
            $appliedNgnPerCrypto = $cryptoAmt > 0 ? $ngnRecv / $cryptoAmt : null;

            // Create transaction
            $transaction = Transaction::create([
                'user_id' => $userId,
                'transaction_id' => Transaction::generateTransactionId(),
                'type' => 'crypto_sell',
                'category' => 'crypto_sale',
                'status' => 'completed',
                'currency' => $currency,
                'amount' => $previewData['crypto_amount'],
                'fee' => 0,
                'total_amount' => $previewData['crypto_amount'],
                'reference' => 'SELL'.strtoupper(substr(md5(uniqid(rand(), true)), 0, 12)),
                'description' => "Sell {$previewData['crypto_amount']} {$currency}",
                'metadata' => [
                    'blockchain' => $blockchain,
                    'network' => $blockchain,
                    'ngn_amount' => $previewData['ngn_amount'],
                    'amount_to_receive' => $previewData['amount_to_receive'],
                    'exchange_rate' => $previewData['exchange_rate'],
                    'reference_ngn_per_crypto' => $referenceNgnPerCrypto !== null ? round($referenceNgnPerCrypto, 8) : null,
                    'applied_ngn_per_crypto' => $appliedNgnPerCrypto !== null ? round($appliedNgnPerCrypto, 8) : null,
                ],
                'completed_at' => now(),
            ]);

            return [
                'success' => true,
                'message' => 'Crypto sale completed successfully',
                'data' => [
                    'transaction' => $transaction,
                    'account' => $this->formatAccount($account->load('walletCurrency.exchangeRate')),
                    'fiat_wallet' => $fiatWallet->fresh(),
                ],
            ];
        });
    }

    /**
     * Send crypto (withdrawal): debit virtual account, broadcast from platform master wallet via Tatum.
     */
    public function sendCrypto(int $userId, array $data): array
    {
        $currency = $data['currency'];
        $blockchain = $data['blockchain'];
        $amount = (float) $data['amount'];
        $address = $data['address'];
        $network = $data['network'] ?? $blockchain;

        if ($amount <= 0) {
            return [
                'success' => false,
                'message' => 'Amount must be greater than zero',
            ];
        }

        if (empty($address) || strlen($address) < 10) {
            return [
                'success' => false,
                'message' => 'Invalid address format',
            ];
        }

        $walletCurrency = WalletCurrency::findActiveForCrypto($currency, $blockchain);

        $rate = $walletCurrency ? $walletCurrency->usdPerUnitForSell() : 1.0;
        if ($rate <= 0) {
            $rate = 1.0;
        }
        $feeRow = $this->platformRates->findCryptoSendOrWithdrawal($currency, $blockchain);
        if ($feeRow === null) {
            $feeInCrypto = self::SEND_FEE_USD / max($rate, 0.0000001);
        } else {
            $pack = $this->computeUsdBasedCryptoFee($feeRow, $amount, $rate);
            $feeInCrypto = $pack['fee_crypto'];
        }
        $totalAmount = $amount + $feeInCrypto;

        $normalizedChain = DepositAddressService::normalizeBlockchain($blockchain);

        $masterWallet = null;
        if (! config('tatum.use_mock')) {
            $masterWallet = MasterWallet::query()
                ->where('blockchain', $normalizedChain)
                ->with('secret')
                ->first();
            if (! $masterWallet) {
                return [
                    'success' => false,
                    'message' => "No master wallet configured for blockchain \"{$normalizedChain}\". Insert a row in master_wallets with encrypted private key.",
                ];
            }
        }

        $ledger = DB::transaction(function () use ($userId, $currency, $blockchain, $amount, $feeInCrypto, $totalAmount, $address, $network) {
            $account = VirtualAccount::where('user_id', $userId)
                ->where('currency', $currency)
                ->where('blockchain', $blockchain)
                ->where('active', true)
                ->lockForUpdate()
                ->first();

            if (! $account) {
                return ['success' => false, 'message' => 'Account not found'];
            }

            if ($account->frozen) {
                return ['success' => false, 'message' => 'This crypto wallet is frozen.'];
            }

            $currentAvailableBalance = (float) ($account->available_balance ?? '0');
            if ($currentAvailableBalance < $totalAmount) {
                return ['success' => false, 'message' => 'Insufficient balance'];
            }

            $currentAccountBalance = (float) ($account->account_balance ?? '0');
            $account->available_balance = (string) ($currentAvailableBalance - $totalAmount);
            $account->account_balance = (string) ($currentAccountBalance - $totalAmount);
            $account->save();

            $transaction = Transaction::create([
                'user_id' => $userId,
                'transaction_id' => Transaction::generateTransactionId(),
                'type' => 'crypto_withdrawal',
                'category' => 'crypto_send',
                'status' => 'pending',
                'currency' => $currency,
                'amount' => $amount,
                'fee' => $feeInCrypto,
                'total_amount' => $totalAmount,
                'reference' => 'SEND'.strtoupper(substr(md5(uniqid('send', true)), 0, 12)),
                'description' => "Send {$amount} {$currency} to {$address}",
                'metadata' => [
                    'blockchain' => $blockchain,
                    'network' => $network,
                    'to_address' => $address,
                    'settlement' => 'master_wallet',
                    'master_wallet_send' => true,
                    'tx_hash' => null,
                ],
                'completed_at' => null,
            ]);

            return [
                'success' => true,
                'transaction' => $transaction,
                'account' => $account->fresh(),
                'virtual_account_id' => $account->id,
                'total_amount' => $totalAmount,
            ];
        });

        if (! ($ledger['success'] ?? false)) {
            return [
                'success' => false,
                'message' => $ledger['message'] ?? 'Ledger update failed',
            ];
        }

        /** @var Transaction $tx */
        $tx = $ledger['transaction'];

        if (config('tatum.use_mock')) {
            $mockHash = '0x'.bin2hex(random_bytes(16));
            $tx->update([
                'status' => 'completed',
                'completed_at' => now(),
                'metadata' => array_merge($tx->metadata ?? [], [
                    'tx_hash' => $mockHash,
                    'tatum_mock' => true,
                ]),
            ]);

            return [
                'success' => true,
                'message' => 'Crypto send completed (TATUM_USE_MOCK — no on-chain broadcast).',
                'data' => [
                    'transaction' => $tx->fresh(),
                    'account' => $this->formatAccount($ledger['account']),
                    'tx_hash' => $mockHash,
                    'payout_status' => 'completed',
                ],
            ];
        }

        try {
            /** @var MasterWallet $masterWallet */
            $broadcast = $this->tatumOutbound->sendExternalFromMasterWallet(
                $masterWallet,
                $address,
                (string) $amount,
                $currency,
                $normalizedChain
            );
        } catch (\Throwable $e) {
            $this->refundVirtualAccountSend($userId, (int) $ledger['virtual_account_id'], (float) $ledger['total_amount']);
            $tx->update([
                'status' => 'failed',
                'metadata' => array_merge($tx->metadata ?? [], [
                    'error' => $e->getMessage(),
                    'broadcast_failed_at' => now()->toIso8601String(),
                ]),
            ]);

            return [
                'success' => false,
                'message' => 'On-chain send failed: '.$e->getMessage(),
            ];
        }

        $tx->update([
            'status' => 'completed',
            'completed_at' => now(),
            'metadata' => array_merge($tx->metadata ?? [], [
                'tx_hash' => $broadcast['txId'],
                'network_fee_actual' => $broadcast['fee'] ?? null,
                'quoted_platform_fee_crypto' => (float) $tx->fee,
                'network_fee_policy' => 'User debited amount + platform fee in crypto; Tatum-reported network fee is logged for reconciliation (treasury absorbs on-chain gas variance vs quote).',
            ]),
        ]);

        MasterWalletTransaction::create([
            'master_wallet_id' => $masterWallet->id,
            'user_id' => $userId,
            'type' => 'external_send',
            'blockchain' => $normalizedChain,
            'currency' => strtoupper($currency),
            'from_address' => $masterWallet->address,
            'to_address' => $address,
            'amount' => (string) $amount,
            'network_fee' => $broadcast['fee'] ?? null,
            'tx_hash' => $broadcast['txId'],
            'internal_transaction_id' => $tx->transaction_id,
            'metadata' => ['tatum' => $broadcast['raw'] ?? []],
        ]);

        return [
            'success' => true,
            'message' => 'Crypto sent on-chain from master wallet.',
            'data' => [
                'transaction' => $tx->fresh(),
                'account' => $this->formatAccount($ledger['account']),
                'tx_hash' => $broadcast['txId'],
                'payout_status' => 'completed',
            ],
        ];
    }

    private function refundVirtualAccountSend(int $userId, int $virtualAccountId, float $totalAmount): void
    {
        DB::transaction(function () use ($userId, $virtualAccountId, $totalAmount) {
            $account = VirtualAccount::where('user_id', $userId)
                ->where('id', $virtualAccountId)
                ->lockForUpdate()
                ->firstOrFail();

            $newAvail = (float) ($account->available_balance ?? '0') + $totalAmount;
            $newBal = (float) ($account->account_balance ?? '0') + $totalAmount;
            $account->update([
                'available_balance' => (string) $newAvail,
                'account_balance' => (string) $newBal,
            ]);
        });
    }

    /**
     * Format account for response
     */
    protected function formatAccount(VirtualAccount $account): array
    {
        $account->loadMissing('walletCurrency.exchangeRate');
        $walletCurrency = $account->walletCurrency;
        $balance = (float) $account->available_balance;
        $rate = $walletCurrency ? $walletCurrency->usdPerUnitForDisplay() : 0.0;
        $usdValue = $balance * $rate;

        return [
            'id' => $account->id,
            'currency' => $account->currency,
            'symbol' => $walletCurrency->symbol ?? $account->currency,
            'name' => $walletCurrency->name ?? $account->currency,
            'blockchain' => $account->blockchain,
            'blockchain_name' => $walletCurrency->blockchain_name ?? $account->blockchain,
            'balance' => $balance,
            'usd_value' => $usdValue,
            'rate' => $rate,
            'account_id' => $account->account_id,
            'is_frozen' => $account->frozen,
        ];
    }

    /**
     * Local-only fake address when `TATUM_USE_MOCK=true` (no Tatum API calls).
     */
    protected function generateDepositAddress(VirtualAccount $account): string
    {
        $prefix = match ($account->blockchain) {
            'BTC' => '1',
            'ETH', 'BSC', 'POLYGON' => '0x',
            default => '',
        };

        return $prefix.bin2hex(random_bytes(20));
    }

    /**
     * Payload for QR encoders: the raw deposit address string (unchanged contract for clients).
     */
    protected function generateQrCode(string $address): string
    {
        return $address;
    }

    protected function findActiveVirtualAccount(int $userId, string $currency, string $blockchain): ?VirtualAccount
    {
        $normalizedCurrency = strtoupper(trim($currency));
        $normalizedBlockchain = DepositAddressService::normalizeBlockchain($blockchain);

        if ($normalizedCurrency === 'USDT') {
            $normalizedCurrency = $this->resolveUsdtLedgerCurrency($normalizedBlockchain);
        }

        if ($normalizedCurrency === 'USDC') {
            $normalizedCurrency = $this->resolveUsdcLedgerCurrency($normalizedBlockchain);
        }

        return VirtualAccount::where('user_id', $userId)
            ->where('currency', $normalizedCurrency)
            ->whereRaw('LOWER(blockchain) = ?', [strtolower($normalizedBlockchain)])
            ->where('active', true)
            ->with('walletCurrency.exchangeRate')
            ->first();
    }
}
