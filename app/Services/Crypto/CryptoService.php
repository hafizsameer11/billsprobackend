<?php

namespace App\Services\Crypto;

use App\Models\Transaction;
use App\Models\VirtualAccount;
use App\Models\WalletCurrency;
use App\Models\FiatWallet;
use App\Services\Transaction\TransactionService;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CryptoService
{
    protected CryptoWalletService $cryptoWalletService;
    protected TransactionService $transactionService;
    protected WalletService $walletService;

    // Transaction fees (in USD)
    const SEND_FEE_USD = 3.0;
    const BUY_FEE_PERCENT = 0.01; // 1%
    const SELL_FEE_PERCENT = 0.01; // 1%
    
    // Exchange rate: $1 = N1,500
    const DEFAULT_EXCHANGE_RATE = 1500.0;

    public function __construct(
        CryptoWalletService $cryptoWalletService,
        TransactionService $transactionService,
        WalletService $walletService
    ) {
        $this->cryptoWalletService = $cryptoWalletService;
        $this->transactionService = $transactionService;
        $this->walletService = $walletService;
    }

    /**
     * Get USDT blockchains
     */
    public function getUsdtBlockchains(): array
    {
        $usdtCurrencies = WalletCurrency::where('currency', 'USDT')
            ->where('is_active', true)
            ->get();

        return $usdtCurrencies->map(function ($currency) {
            return [
                'id' => $currency->id,
                'blockchain' => $currency->blockchain,
                'blockchain_name' => $currency->blockchain_name,
                'network' => $currency->blockchain,
                'currency' => $currency->currency,
                'symbol' => $currency->symbol,
                'contract_address' => $currency->contract_address,
                'decimals' => $currency->decimals,
                'is_token' => $currency->is_token,
                'crediting_time' => '1 min', // Default crediting time
            ];
        })->toArray();
    }

    /**
     * Get virtual accounts (grouped - USDT shown as one)
     */
    public function getVirtualAccountsGrouped(int $userId): array
    {
        $accounts = VirtualAccount::where('user_id', $userId)
            ->where('active', true)
            ->with('walletCurrency')
            ->get();

        $grouped = [];
        $usdtAccounts = [];

        foreach ($accounts as $account) {
            if ($account->currency === 'USDT') {
                $usdtAccounts[] = $account;
            } else {
                $grouped[] = $this->formatAccount($account);
            }
        }

        // Group USDT accounts as one
        if (!empty($usdtAccounts)) {
            $totalUsdtBalance = 0;
            $totalUsdtUsdValue = 0;
            $blockchains = [];

            foreach ($usdtAccounts as $account) {
                $balance = (float) $account->available_balance;
                $totalUsdtBalance += $balance;
                
                if ($account->walletCurrency) {
                    $rate = (float) ($account->walletCurrency->rate ?? 0);
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

        return $grouped;
    }

    /**
     * Get account details by currency and blockchain
     */
    public function getAccountDetails(int $userId, string $currency, ?string $blockchain = null): ?array
    {
        $account = null;
        
        if ($currency === 'USDT' && $blockchain) {
            $account = VirtualAccount::where('user_id', $userId)
                ->where('currency', 'USDT')
                ->where('blockchain', $blockchain)
                ->where('active', true)
                ->with('walletCurrency')
                ->first();
        } else {
            // For non-USDT or USDT without blockchain, get first matching account
            $account = VirtualAccount::where('user_id', $userId)
                ->where('currency', $currency)
                ->where('active', true)
                ->with('walletCurrency')
                ->first();
        }

        if (!$account) {
            return null;
        }

        $accountData = $this->formatAccount($account);

        // Get transactions for this currency and blockchain
        $transactionQuery = Transaction::where('user_id', $userId)
            ->where('currency', $currency)
            ->whereIn('type', ['crypto_buy', 'crypto_sell', 'crypto_withdrawal']);

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
     * Get deposit address for receiving crypto
     */
    public function getDepositAddress(int $userId, string $currency, string $blockchain): array
    {
        $account = VirtualAccount::where('user_id', $userId)
            ->where('currency', $currency)
            ->where('blockchain', $blockchain)
            ->where('active', true)
            ->with('walletCurrency')
            ->first();

        if (!$account) {
            return [
                'success' => false,
                'message' => 'Account not found',
            ];
        }

        // Generate deposit address (for now, generate a mock address)
        // In production, this would integrate with a wallet service
        $depositAddress = $this->generateDepositAddress($account);

        return [
            'success' => true,
            'data' => [
                'currency' => $currency,
                'blockchain' => $blockchain,
                'network' => $blockchain,
                'deposit_address' => $depositAddress,
                'qr_code' => $this->generateQrCode($depositAddress),
                'account_id' => $account->id,
            ],
        ];
    }

    /**
     * Get exchange rate for buy/sell
     */
    public function getExchangeRate(string $fromCurrency, string $toCurrency, float $amount): array
    {
        // Determine which currency is crypto
        $cryptoCurrency = $fromCurrency !== 'NGN' ? $fromCurrency : $toCurrency;
        
        // Get wallet currency for crypto
        $walletCurrency = WalletCurrency::where('currency', $cryptoCurrency)
            ->where('is_active', true)
            ->first();

        if (!$walletCurrency) {
            return [
                'success' => false,
                'message' => 'Currency not found',
            ];
        }

        $rate = (float) $walletCurrency->rate; // Rate to USD
        $exchangeRate = 1.0;

        if ($fromCurrency === 'NGN' && $toCurrency !== 'NGN') {
            // Buying crypto with NGN
            $ngnToUsd = 1 / self::DEFAULT_EXCHANGE_RATE;
            $exchangeRate = $ngnToUsd / $rate;
            $cryptoAmount = $amount * $exchangeRate;
            $fiatAmount = $amount;
        } elseif ($fromCurrency !== 'NGN' && $toCurrency === 'NGN') {
            // Selling crypto to NGN
            $exchangeRate = $rate * self::DEFAULT_EXCHANGE_RATE;
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
                'amount' => $amount,
                'exchange_rate' => $exchangeRate,
                'crypto_amount' => $cryptoAmount,
                'fiat_amount' => $fiatAmount,
                'rate' => $rate,
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
        $paymentMethod = $data['payment_method'] ?? 'naira'; // naira, crypto_wallet

        // Get exchange rate
        $rateData = $this->getExchangeRate('NGN', $currency, $amount);
        if (!$rateData['success']) {
            return $rateData;
        }

        $cryptoAmount = $rateData['data']['crypto_amount'];
        $exchangeRate = $rateData['data']['exchange_rate'];

        // Calculate fees
        $feePercent = self::BUY_FEE_PERCENT;
        $feeInCrypto = $cryptoAmount * $feePercent;
        $totalCryptoAmount = $cryptoAmount + $feeInCrypto;

        // Convert fee to payment currency
        $feeInPaymentCurrency = $feeInCrypto / $exchangeRate;
        $totalAmount = $amount + $feeInPaymentCurrency;

        return [
            'success' => true,
            'data' => [
                'currency' => $currency,
                'blockchain' => $blockchain,
                'network' => $blockchain,
                'amount' => $amount,
                'payment_method' => $paymentMethod,
                'crypto_amount' => $cryptoAmount,
                'fee_percent' => $feePercent * 100,
                'fee_in_crypto' => $feeInCrypto,
                'fee_in_payment_currency' => $feeInPaymentCurrency,
                'total_crypto_amount' => $totalCryptoAmount,
                'total_amount' => $totalAmount,
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
        if (!$preview['success']) {
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
                
            if (!$fiatWallet) {
                // Create wallet if doesn't exist
                $fiatWallet = $this->walletService->createFiatWallet($userId, 'NGN', 'NG');
                // Reload with lock
                $fiatWallet = FiatWallet::where('id', $fiatWallet->id)
                    ->lockForUpdate()
                    ->first();
            }
            
            if ($fiatWallet->balance < $previewData['total_amount']) {
                return [
                    'success' => false,
                    'message' => 'Insufficient balance',
                ];
            }
            
            $fiatWallet->decrement('balance', $previewData['total_amount']);

            // Credit crypto wallet (lock for update to prevent race conditions)
            $account = VirtualAccount::where('user_id', $userId)
                ->where('currency', $currency)
                ->where('blockchain', $blockchain)
                ->where('active', true)
                ->lockForUpdate()
                ->first();

            if (!$account) {
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
                if (!$account) {
                    $walletCurrency = \App\Models\WalletCurrency::where('currency', $currency)
                        ->where('blockchain', $blockchain)
                        ->where('is_active', true)
                        ->first();
                    
                    $accountId = strtoupper($blockchain) . '_' . strtoupper($currency) . '_' . $userId . '_' . time() . '_' . \Illuminate\Support\Str::random(8);
                    
                    $account = VirtualAccount::create([
                        'user_id' => $userId,
                        'currency_id' => $walletCurrency?->id,
                        'blockchain' => $blockchain,
                        'currency' => $currency,
                        'customer_id' => 'CUST_' . $userId,
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
                $account->load('walletCurrency');
            }

            // Create transaction
            $transaction = Transaction::create([
                'user_id' => $userId,
                'transaction_id' => Transaction::generateTransactionId(),
                'type' => 'crypto_buy',
                'category' => 'crypto_purchase',
                'status' => 'completed',
                'currency' => $currency,
                'amount' => $previewData['crypto_amount'],
                'fee' => $previewData['fee_in_crypto'],
                'total_amount' => $previewData['total_crypto_amount'],
                'reference' => 'BUY' . strtoupper(substr(md5(uniqid(rand(), true)), 0, 12)),
                'description' => "Buy {$previewData['crypto_amount']} {$currency}",
                'metadata' => [
                    'blockchain' => $blockchain,
                    'network' => $blockchain,
                    'payment_method' => $paymentMethod,
                    'payment_amount' => $previewData['amount'],
                    'payment_currency' => $paymentMethod === 'naira' ? 'NGN' : 'USD',
                    'exchange_rate' => $previewData['exchange_rate'],
                ],
                'completed_at' => now(),
            ]);

            return [
                'success' => true,
                'message' => 'Crypto purchase completed successfully',
                'data' => [
                    'transaction' => $transaction,
                    'account' => $account ? $this->formatAccount($account->load('walletCurrency')) : null,
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
            ->with('walletCurrency')
            ->first();

        if (!$account) {
            return [
                'success' => false,
                'message' => 'Account not found',
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

        // Get exchange rate
        $rateData = $this->getExchangeRate($currency, 'NGN', $amount);
        if (!$rateData['success']) {
            return $rateData;
        }

        $ngnAmount = $rateData['data']['fiat_amount'];
        $exchangeRate = $rateData['data']['exchange_rate'];

        // Calculate fees
        $feePercent = self::SELL_FEE_PERCENT;
        $feeInCrypto = $amount * $feePercent;
        $feeInNgn = $feeInCrypto * $exchangeRate;
        $totalCryptoAmount = $amount + $feeInCrypto;
        $amountToReceive = $ngnAmount - $feeInNgn;

        return [
            'success' => true,
            'data' => [
                'currency' => $currency,
                'blockchain' => $blockchain,
                'network' => $blockchain,
                'crypto_amount' => $amount,
                'fee_percent' => $feePercent * 100,
                'fee_in_crypto' => $feeInCrypto,
                'fee_in_ngn' => $feeInNgn,
                'total_crypto_amount' => $totalCryptoAmount,
                'ngn_amount' => $ngnAmount,
                'amount_to_receive' => $amountToReceive,
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
        if (!$preview['success']) {
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

            if (!$account) {
                return [
                    'success' => false,
                    'message' => 'Account not found',
                ];
            }

            // Check balance inside transaction with lock
            $currentAvailableBalance = (float) ($account->available_balance ?? '0');
            if ($currentAvailableBalance < $previewData['total_crypto_amount']) {
                return [
                    'success' => false,
                    'message' => 'Insufficient balance',
                ];
            }

            // Manually update balances since they're stored as strings
            $currentAccountBalance = (float) ($account->account_balance ?? '0');
            $newAvailableBalance = $currentAvailableBalance - $previewData['total_crypto_amount'];
            $newAccountBalance = $currentAccountBalance - $previewData['total_crypto_amount'];
            
            $account->available_balance = (string) $newAvailableBalance;
            $account->account_balance = (string) $newAccountBalance;
            $account->save();
            
            // Refresh account with relationships
            $account->refresh();
            $account->load('walletCurrency');

            // Credit Naira wallet with lock
            $fiatWallet = FiatWallet::where('user_id', $userId)
                ->where('currency', 'NGN')
                ->where('country_code', 'NG')
                ->lockForUpdate()
                ->first();
                
            if (!$fiatWallet) {
                // Create wallet if doesn't exist (outside lock since it's new)
                $fiatWallet = $this->walletService->createFiatWallet($userId, 'NGN', 'NG');
            }
            $fiatWallet->increment('balance', $previewData['amount_to_receive']);

            // Create transaction
            $transaction = Transaction::create([
                'user_id' => $userId,
                'transaction_id' => Transaction::generateTransactionId(),
                'type' => 'crypto_sell',
                'category' => 'crypto_sale',
                'status' => 'completed',
                'currency' => $currency,
                'amount' => $previewData['crypto_amount'],
                'fee' => $previewData['fee_in_crypto'],
                'total_amount' => $previewData['total_crypto_amount'],
                'reference' => 'SELL' . strtoupper(substr(md5(uniqid(rand(), true)), 0, 12)),
                'description' => "Sell {$previewData['crypto_amount']} {$currency}",
                'metadata' => [
                    'blockchain' => $blockchain,
                    'network' => $blockchain,
                    'ngn_amount' => $previewData['ngn_amount'],
                    'amount_to_receive' => $previewData['amount_to_receive'],
                    'exchange_rate' => $previewData['exchange_rate'],
                ],
                'completed_at' => now(),
            ]);

            return [
                'success' => true,
                'message' => 'Crypto sale completed successfully',
                'data' => [
                    'transaction' => $transaction,
                    'account' => $this->formatAccount($account->load('walletCurrency')),
                    'fiat_wallet' => $fiatWallet->fresh(),
                ],
            ];
        });
    }

    /**
     * Send crypto (withdrawal)
     */
    public function sendCrypto(int $userId, array $data): array
    {
        $currency = $data['currency'];
        $blockchain = $data['blockchain'];
        $amount = (float) $data['amount'];
        $address = $data['address'];
        $network = $data['network'] ?? $blockchain;

        // Validate amount
        if ($amount <= 0) {
            return [
                'success' => false,
                'message' => 'Amount must be greater than zero',
            ];
        }

        // Validate address format (basic check)
        if (empty($address) || strlen($address) < 10) {
            return [
                'success' => false,
                'message' => 'Invalid address format',
            ];
        }

        // Calculate fee
        $feeInUsd = self::SEND_FEE_USD;
        $walletCurrency = WalletCurrency::where('currency', $currency)
            ->where('blockchain', $blockchain)
            ->first();
        
        $rate = $walletCurrency ? (float) $walletCurrency->rate : 1.0;
        $feeInCrypto = $feeInUsd / $rate;
        $totalAmount = $amount + $feeInCrypto;

        return DB::transaction(function () use ($userId, $currency, $blockchain, $amount, $feeInCrypto, $totalAmount, $address, $network) {
            // Lock account for update to prevent race conditions
            $account = VirtualAccount::where('user_id', $userId)
                ->where('currency', $currency)
                ->where('blockchain', $blockchain)
                ->where('active', true)
                ->lockForUpdate()
                ->first();

            if (!$account) {
                return [
                    'success' => false,
                    'message' => 'Account not found',
                ];
            }

            // Check balance inside transaction with lock
            $currentAvailableBalance = (float) ($account->available_balance ?? '0');
            if ($currentAvailableBalance < $totalAmount) {
                return [
                    'success' => false,
                    'message' => 'Insufficient balance',
                ];
            }

            // Manually update balances since they're stored as strings
            $currentAccountBalance = (float) ($account->account_balance ?? '0');
            $newAvailableBalance = $currentAvailableBalance - $totalAmount;
            $newAccountBalance = $currentAccountBalance - $totalAmount;
            
            $account->available_balance = (string) $newAvailableBalance;
            $account->account_balance = (string) $newAccountBalance;
            $account->save();

            // Generate transaction hash (mock for now)
            $txHash = '0x' . bin2hex(random_bytes(32));

            // Create transaction
            $transaction = Transaction::create([
                'user_id' => $userId,
                'transaction_id' => Transaction::generateTransactionId(),
                'type' => 'crypto_withdrawal',
                'category' => 'crypto_send',
                'status' => 'completed',
                'currency' => $currency,
                'amount' => $amount,
                'fee' => $feeInCrypto,
                'total_amount' => $totalAmount,
                'reference' => 'SEND' . strtoupper(substr(md5(uniqid(rand(), true)), 0, 12)),
                'description' => "Send {$amount} {$currency} to {$address}",
                'metadata' => [
                    'blockchain' => $blockchain,
                    'network' => $network,
                    'address' => $address,
                    'tx_hash' => $txHash,
                ],
                'completed_at' => now(),
            ]);

            return [
                'success' => true,
                'message' => 'Crypto sent successfully',
                'data' => [
                    'transaction' => $transaction,
                    'account' => $this->formatAccount($account->fresh()),
                    'tx_hash' => $txHash,
                ],
            ];
        });
    }

    /**
     * Format account for response
     */
    protected function formatAccount(VirtualAccount $account): array
    {
        $walletCurrency = $account->walletCurrency;
        $balance = (float) $account->available_balance;
        $rate = $walletCurrency ? (float) ($walletCurrency->rate ?? 0) : 0;
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
     * Generate deposit address (mock for now)
     */
    protected function generateDepositAddress(VirtualAccount $account): string
    {
        // In production, this would integrate with a wallet service
        // For now, generate a mock address
        $prefix = match($account->blockchain) {
            'BTC' => '1',
            'ETH', 'BSC', 'POLYGON' => '0x',
            default => '',
        };

        return $prefix . bin2hex(random_bytes(20));
    }

    /**
     * Generate QR code data (mock for now)
     */
    protected function generateQrCode(string $address): string
    {
        // In production, this would generate an actual QR code
        // For now, return the address as QR data
        return $address;
    }
}
