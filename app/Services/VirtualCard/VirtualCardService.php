<?php

namespace App\Services\VirtualCard;

use App\Models\VirtualCard;
use App\Models\VirtualCardTransaction;
use App\Models\Transaction;
use App\Models\FiatWallet;
use App\Services\Auth\AuthService;
use App\Services\Wallet\WalletService;
use App\Services\Crypto\CryptoWalletService;
use Illuminate\Support\Facades\DB;

class VirtualCardService
{
    protected AuthService $authService;
    protected WalletService $walletService;
    protected CryptoWalletService $cryptoWalletService;

    // Virtual cards always use USD as the primary currency
    const CARD_CURRENCY = 'USD';
    
    // Card creation fee: $3
    const CARD_CREATION_FEE = 3.0;
    // Exchange rate: $1 = N1,500 (example)
    const DEFAULT_EXCHANGE_RATE = 1500.0;
    // Funding/Withdrawal fee: N500
    const FUNDING_FEE = 500.0;

    public function __construct(
        AuthService $authService,
        WalletService $walletService,
        CryptoWalletService $cryptoWalletService
    ) {
        $this->authService = $authService;
        $this->walletService = $walletService;
        $this->cryptoWalletService = $cryptoWalletService;
    }

    /**
     * Create virtual card
     */
    public function createCard(int $userId, array $data): array
    {
        // Check if user has sufficient balance for card creation fee
        $paymentWallet = $this->getPaymentWallet($userId, $data['payment_wallet_type'] ?? 'naira_wallet', $data['payment_wallet_currency'] ?? 'NGN');
        
        if (!$paymentWallet) {
            return [
                'success' => false,
                'message' => 'Payment wallet not found',
            ];
        }

        $feeInWalletCurrency = $this->convertToWalletCurrency(self::CARD_CREATION_FEE, 'USD', $data['payment_wallet_currency'] ?? 'NGN');
        $totalFee = $feeInWalletCurrency + self::FUNDING_FEE; // Card fee + processing fee

        if ($paymentWallet['balance'] < $totalFee) {
            return [
                'success' => false,
                'message' => 'Insufficient balance for card creation fee',
            ];
        }

        return DB::transaction(function () use ($userId, $data, $paymentWallet, $totalFee) {
            // Generate card details
            $cardNumber = VirtualCard::generateCardNumber();
            $cvv = VirtualCard::generateCvv();
            $expiry = VirtualCard::generateExpiry();

            // Create virtual card (always in USD)
            $card = VirtualCard::create([
                'user_id' => $userId,
                'card_name' => $data['card_name'],
                'card_number' => $cardNumber,
                'cvv' => $cvv,
                'expiry_month' => $expiry['expiry_month'],
                'expiry_year' => $expiry['expiry_year'],
                'card_type' => $data['card_type'] ?? 'mastercard',
                'card_color' => $data['card_color'] ?? 'green',
                'currency' => self::CARD_CURRENCY, // Always USD
                'balance' => 0,
                'daily_spending_limit' => $data['daily_spending_limit'] ?? 2000,
                'monthly_spending_limit' => $data['monthly_spending_limit'] ?? 20000,
                'daily_transaction_limit' => $data['daily_transaction_limit'] ?? 5,
                'monthly_transaction_limit' => $data['monthly_transaction_limit'] ?? 50,
                'billing_address_street' => $data['billing_address_street'] ?? null,
                'billing_address_city' => $data['billing_address_city'] ?? null,
                'billing_address_state' => $data['billing_address_state'] ?? null,
                'billing_address_country' => $data['billing_address_country'] ?? null,
                'billing_address_postal_code' => $data['billing_address_postal_code'] ?? null,
            ]);

            // Deduct card creation fee
            $this->deductFromWallet($userId, $paymentWallet['type'], $paymentWallet['currency'], $totalFee);

            // Create transaction record
            $transaction = Transaction::create([
                'user_id' => $userId,
                'transaction_id' => Transaction::generateTransactionId(),
                'type' => 'card_creation',
                'category' => 'virtual_card',
                'status' => 'completed',
                'currency' => $paymentWallet['currency'],
                'amount' => 0,
                'fee' => $totalFee,
                'total_amount' => $totalFee,
                'reference' => 'CARD' . strtoupper(substr(md5(uniqid(rand(), true)), 0, 12)),
                'description' => 'Virtual card creation fee',
                'metadata' => [
                    'card_id' => $card->id,
                    'card_name' => $card->card_name,
                    'card_fee_usd' => self::CARD_CREATION_FEE,
                    'processing_fee' => self::FUNDING_FEE,
                ],
            ]);

            return [
                'success' => true,
                'message' => 'Virtual card created successfully',
                'data' => [
                    'card' => $card,
                    'transaction' => $transaction,
                ],
            ];
        });
    }

    /**
     * Fund virtual card
     */
    public function fundCard(int $userId, int $cardId, array $data): array
    {
        $card = VirtualCard::where('id', $cardId)
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->firstOrFail();

        $amountUsd = (float) $data['amount'];
        $paymentWalletType = $data['payment_wallet_type'] ?? 'naira_wallet';
        $paymentWalletCurrency = $data['payment_wallet_currency'] ?? 'NGN';

        // Get payment wallet
        $paymentWallet = $this->getPaymentWallet($userId, $paymentWalletType, $paymentWalletCurrency);
        if (!$paymentWallet) {
            return [
                'success' => false,
                'message' => 'Payment wallet not found',
            ];
        }

        // Calculate amounts
        $exchangeRate = $this->getExchangeRate('USD', $paymentWalletCurrency);
        $amountInWalletCurrency = $amountUsd * $exchangeRate;
        $fee = self::FUNDING_FEE;
        $totalAmount = $amountInWalletCurrency + $fee;

        // Check balance
        if ($paymentWallet['balance'] < $totalAmount) {
            return [
                'success' => false,
                'message' => 'Insufficient balance',
            ];
        }

        return DB::transaction(function () use ($userId, $card, $amountUsd, $paymentWalletType, $paymentWalletCurrency, $amountInWalletCurrency, $fee, $totalAmount, $exchangeRate) {
            // Lock card for update
            $card = VirtualCard::where('id', $card->id)
                ->where('user_id', $userId)
                ->where('is_active', true)
                ->lockForUpdate()
                ->firstOrFail();

            // Deduct from wallet (with lock inside deductFromWallet)
            $this->deductFromWallet($userId, $paymentWalletType, $paymentWalletCurrency, $totalAmount);

            // Credit card
            $card->increment('balance', $amountUsd);

            // Create main transaction
            $transaction = Transaction::create([
                'user_id' => $userId,
                'transaction_id' => Transaction::generateTransactionId(),
                'type' => 'card_funding',
                'category' => 'virtual_card',
                'status' => 'completed',
                'currency' => $paymentWalletCurrency,
                'amount' => $amountInWalletCurrency,
                'fee' => $fee,
                'total_amount' => $totalAmount,
                'reference' => 'FUND' . strtoupper(substr(md5(uniqid(rand(), true)), 0, 12)),
                'description' => "Fund virtual card with \${$amountUsd}",
                'metadata' => [
                    'card_id' => $card->id,
                    'card_name' => $card->card_name,
                    'amount_usd' => $amountUsd,
                    'payment_wallet_type' => $paymentWalletType,
                    'exchange_rate' => $exchangeRate,
                ],
            ]);

            // Create card transaction (always in USD)
            VirtualCardTransaction::create([
                'virtual_card_id' => $card->id,
                'user_id' => $userId,
                'transaction_id' => $transaction->id,
                'type' => 'fund',
                'status' => 'completed',
                'currency' => self::CARD_CURRENCY, // Always USD
                'amount' => $amountUsd,
                'fee' => 0,
                'total_amount' => $amountUsd,
                'payment_wallet_type' => $paymentWalletType,
                'payment_wallet_currency' => $paymentWalletCurrency,
                'exchange_rate' => $exchangeRate,
                'reference' => $transaction->reference,
                'description' => "Fund card with \${$amountUsd}",
            ]);

            return [
                'success' => true,
                'message' => 'Card funded successfully',
                'data' => [
                    'card' => $card->fresh(),
                    'transaction' => $transaction,
                    'amount_funded_usd' => $amountUsd,
                    'amount_paid' => $totalAmount,
                    'currency' => $paymentWalletCurrency,
                ],
            ];
        });
    }

    /**
     * Withdraw from virtual card
     */
    public function withdrawFromCard(int $userId, int $cardId, array $data): array
    {
        $card = VirtualCard::where('id', $cardId)
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->where('is_frozen', false)
            ->firstOrFail();

        $amountUsd = (float) $data['amount'];

        // Check card balance
        if ($card->balance < $amountUsd) {
            return [
                'success' => false,
                'message' => 'Insufficient card balance',
            ];
        }

        // Calculate amounts (withdraw to Naira wallet)
        $exchangeRate = $this->getExchangeRate('USD', 'NGN');
        $amountInNgn = $amountUsd * $exchangeRate;
        $fee = self::FUNDING_FEE;
        $amountToReceive = $amountInNgn - $fee;

        return DB::transaction(function () use ($userId, $card, $amountUsd, $amountInNgn, $fee, $amountToReceive, $exchangeRate) {
            // Lock card for update
            $card = VirtualCard::where('id', $card->id)
                ->where('user_id', $userId)
                ->where('is_active', true)
                ->where('is_frozen', false)
                ->lockForUpdate()
                ->firstOrFail();

            // Check balance again inside transaction
            if ($card->balance < $amountUsd) {
                return [
                    'success' => false,
                    'message' => 'Insufficient card balance',
                ];
            }

            // Debit card
            $card->decrement('balance', $amountUsd);

            // Credit Naira wallet
            $nairaWallet = $this->walletService->getFiatWallet($userId, 'NGN', 'NG');
            if ($nairaWallet) {
                $nairaWallet->increment('balance', $amountToReceive);
            } else {
                // Create wallet if doesn't exist
                $nairaWallet = $this->walletService->createFiatWallet($userId, 'NGN', 'NG');
                $nairaWallet->increment('balance', $amountToReceive);
            }

            // Create main transaction
            $transaction = Transaction::create([
                'user_id' => $userId,
                'transaction_id' => Transaction::generateTransactionId(),
                'type' => 'card_withdrawal',
                'category' => 'virtual_card',
                'status' => 'completed',
                'currency' => 'NGN',
                'amount' => $amountToReceive,
                'fee' => $fee,
                'total_amount' => $amountInNgn,
                'reference' => 'WDRW' . strtoupper(substr(md5(uniqid(rand(), true)), 0, 12)),
                'description' => "Withdraw \${$amountUsd} from virtual card",
                'metadata' => [
                    'card_id' => $card->id,
                    'card_name' => $card->card_name,
                    'amount_usd' => $amountUsd,
                    'exchange_rate' => $exchangeRate,
                ],
            ]);

            // Create card transaction (always in USD)
            VirtualCardTransaction::create([
                'virtual_card_id' => $card->id,
                'user_id' => $userId,
                'transaction_id' => $transaction->id,
                'type' => 'withdraw',
                'status' => 'completed',
                'currency' => self::CARD_CURRENCY, // Always USD
                'amount' => $amountUsd,
                'fee' => 0,
                'total_amount' => $amountUsd,
                'payment_wallet_type' => 'naira_wallet',
                'payment_wallet_currency' => 'NGN',
                'exchange_rate' => $exchangeRate,
                'reference' => $transaction->reference,
                'description' => "Withdraw \${$amountUsd} to Naira wallet",
            ]);

            return [
                'success' => true,
                'message' => 'Withdrawal successful',
                'data' => [
                    'card' => $card->fresh(),
                    'transaction' => $transaction,
                    'amount_withdrawn_usd' => $amountUsd,
                    'amount_received_ngn' => $amountToReceive,
                    'fee' => $fee,
                ],
            ];
        });
    }

    /**
     * Freeze/Unfreeze card
     */
    public function toggleFreeze(int $userId, int $cardId, bool $freeze = true): array
    {
        $card = VirtualCard::where('id', $cardId)
            ->where('user_id', $userId)
            ->firstOrFail();

        $card->update(['is_frozen' => $freeze]);

        return [
            'success' => true,
            'message' => $freeze ? 'Card frozen successfully' : 'Card unfrozen successfully',
            'data' => [
                'card' => $card->fresh(),
                'is_frozen' => $freeze,
            ],
        ];
    }

    /**
     * Get user's virtual cards
     */
    public function getUserCards(int $userId): \Illuminate\Database\Eloquent\Collection
    {
        return VirtualCard::where('user_id', $userId)
            ->where('is_active', true)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get card by ID
     */
    public function getCard(int $userId, int $cardId): ?VirtualCard
    {
        return VirtualCard::where('id', $cardId)
            ->where('user_id', $userId)
            ->first();
    }

    /**
     * Get card transactions
     */
    public function getCardTransactions(int $userId, int $cardId, int $limit = 50): \Illuminate\Database\Eloquent\Collection
    {
        return VirtualCardTransaction::where('virtual_card_id', $cardId)
            ->where('user_id', $userId)
            ->with(['transaction'])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Update card limits
     */
    public function updateCardLimits(int $userId, int $cardId, array $data): VirtualCard
    {
        $card = VirtualCard::where('id', $cardId)
            ->where('user_id', $userId)
            ->firstOrFail();

        $card->update([
            'daily_spending_limit' => $data['daily_spending_limit'] ?? $card->daily_spending_limit,
            'monthly_spending_limit' => $data['monthly_spending_limit'] ?? $card->monthly_spending_limit,
            'daily_transaction_limit' => $data['daily_transaction_limit'] ?? $card->daily_transaction_limit,
            'monthly_transaction_limit' => $data['monthly_transaction_limit'] ?? $card->monthly_transaction_limit,
        ]);

        return $card->fresh();
    }

    /**
     * Get payment wallet (Naira or Crypto)
     */
    protected function getPaymentWallet(int $userId, string $type, string $currency): ?array
    {
        if ($type === 'naira_wallet') {
            $wallet = $this->walletService->getFiatWallet($userId, $currency, 'NG');
            if ($wallet) {
                return [
                    'type' => 'naira_wallet',
                    'currency' => $currency,
                    'balance' => (float) $wallet->balance,
                ];
            }
        } elseif ($type === 'crypto_wallet') {
            // Get crypto balance in USD
            $balanceUsd = $this->cryptoWalletService->getTotalCryptoBalanceInUsd($userId);
            return [
                'type' => 'crypto_wallet',
                'currency' => self::CARD_CURRENCY, // Always USD
                'balance' => $balanceUsd,
            ];
        }

        return null;
    }

    /**
     * Deduct from wallet
     */
    protected function deductFromWallet(int $userId, string $type, string $currency, float $amount): void
    {
        if ($type === 'naira_wallet') {
            // Lock wallet for update (should already be in transaction)
            $wallet = FiatWallet::where('user_id', $userId)
                ->where('currency', $currency)
                ->where('country_code', 'NG')
                ->lockForUpdate()
                ->first();
            if ($wallet) {
                // Check balance before deducting
                if ($wallet->balance < $amount) {
                    throw new \Exception('Insufficient balance');
                }
                $wallet->decrement('balance', $amount);
            }
        } elseif ($type === 'crypto_wallet') {
            // For crypto wallet, convert USD amount to crypto and deduct proportionally
            // For now, we'll deduct from fiat wallet if crypto balance is insufficient
            // In production, this should properly handle crypto wallet deduction
            $cryptoBalanceUsd = $this->cryptoWalletService->getTotalCryptoBalanceInUsd($userId);
            if ($cryptoBalanceUsd >= $amount) {
                // TODO: Implement proper crypto wallet deduction
                // For now, we'll use a simplified approach
            } else {
                // Fallback to Naira wallet if crypto insufficient
                $nairaWallet = $this->walletService->getFiatWallet($userId, 'NGN', 'NG');
                if ($nairaWallet) {
                    $amountInNgn = $this->convertToWalletCurrency($amount, 'USD', 'NGN');
                    $nairaWallet->decrement('balance', $amountInNgn);
                }
            }
        }
    }

    /**
     * Get exchange rate
     */
    protected function getExchangeRate(string $from, string $to): float
    {
        // Default exchange rate: $1 = N1,500
        if ($from === 'USD' && $to === 'NGN') {
            return self::DEFAULT_EXCHANGE_RATE;
        } elseif ($from === 'NGN' && $to === 'USD') {
            return 1 / self::DEFAULT_EXCHANGE_RATE;
        }

        return 1.0; // Same currency
    }

    /**
     * Convert amount to wallet currency
     */
    protected function convertToWalletCurrency(float $amount, string $fromCurrency, string $toCurrency): float
    {
        if ($fromCurrency === $toCurrency) {
            return $amount;
        }

        $rate = $this->getExchangeRate($fromCurrency, $toCurrency);
        return $amount * $rate;
    }
}
