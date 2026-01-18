<?php

namespace App\Services\BillPayment;

use App\Models\BillPaymentCategory;
use App\Models\BillPaymentProvider;
use App\Models\BillPaymentPlan;
use App\Models\Beneficiary;
use App\Models\Transaction;
use App\Models\FiatWallet;
use App\Services\Auth\AuthService;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class BillPaymentService
{
    protected AuthService $authService;
    protected WalletService $walletService;

    public function __construct(
        AuthService $authService,
        WalletService $walletService
    ) {
        $this->authService = $authService;
        $this->walletService = $walletService;
    }

    /**
     * Get all active categories
     */
    public function getCategories(): \Illuminate\Database\Eloquent\Collection
    {
        return BillPaymentCategory::where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    /**
     * Get providers by category
     */
    public function getProvidersByCategory(string $categoryCode, ?string $countryCode = null): \Illuminate\Database\Eloquent\Collection
    {
        $category = BillPaymentCategory::where('code', $categoryCode)
            ->where('is_active', true)
            ->firstOrFail();

        $query = BillPaymentProvider::where('category_id', $category->id)
            ->where('is_active', true)
            ->with('category');

        if ($countryCode) {
            $query->where('country_code', $countryCode);
        }

        return $query->orderBy('name')->get();
    }

    /**
     * Get plans by provider
     */
    public function getPlansByProvider(int $providerId): \Illuminate\Database\Eloquent\Collection
    {
        $provider = BillPaymentProvider::where('id', $providerId)
            ->where('is_active', true)
            ->firstOrFail();

        return BillPaymentPlan::where('provider_id', $providerId)
            ->where('is_active', true)
            ->orderBy('amount', 'asc')
            ->get();
    }

    /**
     * Validate meter number (Electricity)
     */
    public function validateMeter(int $providerId, string $meterNumber, string $accountType): array
    {
        $provider = BillPaymentProvider::where('id', $providerId)
            ->whereHas('category', function($q) {
                $q->where('code', 'electricity');
            })
            ->firstOrFail();

        // Validate meter number format (min 8 characters)
        if (strlen($meterNumber) < 8) {
            return [
                'success' => false,
                'message' => 'Invalid meter number format. Must be at least 8 characters.',
            ];
        }

        // TODO: In production, call provider API for real validation
        // For now, simulate validation
        return [
            'success' => true,
            'data' => [
                'isValid' => true,
                'accountName' => 'John Doe', // Simulated
                'meterNumber' => $meterNumber,
                'accountType' => $accountType,
                'provider' => [
                    'id' => $provider->id,
                    'name' => $provider->name,
                    'code' => $provider->code,
                ],
            ],
        ];
    }

    /**
     * Validate account number (Betting)
     */
    public function validateAccount(int $providerId, string $accountNumber): array
    {
        $provider = BillPaymentProvider::where('id', $providerId)
            ->whereHas('category', function($q) {
                $q->where('code', 'betting');
            })
            ->firstOrFail();

        // Validate account number format (min 5 characters)
        if (strlen($accountNumber) < 5) {
            return [
                'success' => false,
                'message' => 'Invalid account number format. Must be at least 5 characters.',
            ];
        }

        // TODO: In production, call provider API for real validation
        return [
            'success' => true,
            'data' => [
                'isValid' => true,
                'accountNumber' => $accountNumber,
                'provider' => [
                    'id' => $provider->id,
                    'name' => $provider->name,
                    'code' => $provider->code,
                ],
            ],
        ];
    }

    /**
     * Calculate fee
     */
    protected function calculateFee(float $amount, string $currency): float
    {
        $feePercent = 0.01; // 1%
        $calculatedFee = $amount * $feePercent;

        $minFees = [
            'NGN' => 20,
            'USD' => 0.1,
            'KES' => 2,
            'GHS' => 0.5,
        ];

        $minFee = $minFees[$currency] ?? 0.1;
        return max($calculatedFee, $minFee);
    }

    /**
     * Preview bill payment (calculate fees without executing)
     */
    public function previewBillPayment(int $userId, array $data): array
    {
        // Get category
        $category = BillPaymentCategory::where('code', $data['categoryCode'])
            ->where('is_active', true)
            ->firstOrFail();

        // Get provider
        $provider = BillPaymentProvider::where('id', $data['providerId'])
            ->where('category_id', $category->id)
            ->where('is_active', true)
            ->firstOrFail();

        // Determine amount
        $amount = 0;
        $plan = null;

        if (in_array($category->code, ['data', 'cable_tv']) && isset($data['planId'])) {
            // Plan-based payment
            $plan = BillPaymentPlan::where('id', $data['planId'])
                ->where('provider_id', $provider->id)
                ->where('is_active', true)
                ->firstOrFail();
            $amount = (float) $plan->amount;
        } elseif (isset($data['amount'])) {
            // Custom amount payment
            $amount = (float) $data['amount'];
        } else {
            return [
                'success' => false,
                'message' => 'Amount or planId is required',
            ];
        }

        // Calculate fee
        $currency = $data['currency'] ?? 'NGN';
        $fee = $this->calculateFee($amount, $currency);
        $totalAmount = $amount + $fee;

        // Get wallet balance
        $wallet = $this->walletService->getFiatWallet($userId, $currency);
        $walletBalance = $wallet ? (float) $wallet->balance : 0;

        return [
            'success' => true,
            'data' => [
                'category' => [
                    'id' => $category->id,
                    'code' => $category->code,
                    'name' => $category->name,
                ],
                'provider' => [
                    'id' => $provider->id,
                    'code' => $provider->code,
                    'name' => $provider->name,
                    'logo_url' => $provider->logo_url,
                ],
                'plan' => $plan ? [
                    'id' => $plan->id,
                    'code' => $plan->code,
                    'name' => $plan->name,
                    'amount' => $plan->amount,
                ] : null,
                'amount' => $amount,
                'currency' => $currency,
                'fee' => $fee,
                'fee_percent' => 1.0, // 1%
                'total_amount' => $totalAmount,
                'wallet_balance' => $walletBalance,
                'sufficient_balance' => $walletBalance >= $totalAmount,
            ],
        ];
    }

    /**
     * Initiate bill payment
     */
    public function initiateBillPayment(int $userId, array $data): array
    {
        // Get category
        $category = BillPaymentCategory::where('code', $data['categoryCode'])
            ->where('is_active', true)
            ->firstOrFail();

        // Get provider
        $provider = BillPaymentProvider::where('id', $data['providerId'])
            ->where('category_id', $category->id)
            ->where('is_active', true)
            ->firstOrFail();

        // Get wallet
        $wallet = $this->walletService->getFiatWallet($userId, $data['currency'] ?? 'NGN');
        if (!$wallet) {
            return [
                'success' => false,
                'message' => "Wallet for {$data['currency']} not found",
            ];
        }

        // Determine amount
        $amount = 0;
        $plan = null;

        if (in_array($category->code, ['data', 'cable_tv']) && isset($data['planId'])) {
            // Plan-based payment
            $plan = BillPaymentPlan::where('id', $data['planId'])
                ->where('provider_id', $provider->id)
                ->where('is_active', true)
                ->firstOrFail();
            $amount = (float) $plan->amount;
        } elseif (isset($data['amount'])) {
            // Custom amount payment
            $amount = (float) $data['amount'];
        } else {
            return [
                'success' => false,
                'message' => 'Amount or planId is required',
            ];
        }

        // Get account number
        $accountNumber = null;
        $accountName = null;
        $accountType = $data['accountType'] ?? null;

        if (isset($data['beneficiaryId'])) {
            $beneficiary = Beneficiary::where('id', $data['beneficiaryId'])
                ->where('user_id', $userId)
                ->where('is_active', true)
                ->firstOrFail();
            $accountNumber = $beneficiary->account_number;
            $accountName = $beneficiary->name;
            $accountType = $beneficiary->account_type;
        } elseif (isset($data['accountNumber'])) {
            $accountNumber = $data['accountNumber'];
        } else {
            return [
                'success' => false,
                'message' => 'Account number or beneficiaryId is required',
            ];
        }

        // Validate account/meter if required
        if ($category->code === 'electricity') {
            if (!$accountType) {
                return [
                    'success' => false,
                    'message' => 'Account type (prepaid/postpaid) is required for electricity',
                ];
            }
            // Meter validation should be done before initiate
        } elseif ($category->code === 'betting') {
            // Account validation should be done before initiate
        }

        // Calculate fee
        $fee = $this->calculateFee($amount, $data['currency'] ?? 'NGN');
        $totalAmount = $amount + $fee;

        // Note: Balance check is done here for early validation
        // Final check will be done in confirm with lockForUpdate
        if ($wallet->balance < $totalAmount) {
            return [
                'success' => false,
                'message' => 'Insufficient balance',
            ];
        }

        // Create transaction
        $transaction = Transaction::create([
            'user_id' => $userId,
            'transaction_id' => Transaction::generateTransactionId(),
            'type' => 'bill_payment',
            'category' => $category->code,
            'status' => 'pending',
            'currency' => $data['currency'] ?? 'NGN',
            'amount' => $amount,
            'fee' => $fee,
            'total_amount' => $totalAmount,
            'reference' => 'BILL' . strtoupper(substr(md5(uniqid(rand(), true)), 0, 12)),
            'description' => "{$category->name} payment - {$provider->name}",
            'metadata' => [
                'categoryCode' => $category->code,
                'categoryName' => $category->name,
                'providerId' => $provider->id,
                'providerCode' => $provider->code,
                'providerName' => $provider->name,
                'accountNumber' => $accountNumber,
                'accountName' => $accountName,
                'accountType' => $accountType,
                'planId' => $plan?->id,
                'planCode' => $plan?->code,
                'planName' => $plan?->name,
                'planDataAmount' => $plan?->data_amount,
                'beneficiaryId' => $data['beneficiaryId'] ?? null,
            ],
        ]);

        return [
            'success' => true,
            'data' => [
                'transactionId' => $transaction->id,
                'reference' => $transaction->reference,
                'category' => [
                    'id' => $category->id,
                    'code' => $category->code,
                    'name' => $category->name,
                ],
                'provider' => [
                    'id' => $provider->id,
                    'code' => $provider->code,
                    'name' => $provider->name,
                    'logoUrl' => $provider->logo_url,
                ],
                'plan' => $plan ? [
                    'id' => $plan->id,
                    'code' => $plan->code,
                    'name' => $plan->name,
                    'amount' => $plan->amount,
                    'dataAmount' => $plan->data_amount,
                ] : null,
                'accountNumber' => $accountNumber,
                'accountName' => $accountName,
                'accountType' => $accountType,
                'amount' => (string) $amount,
                'currency' => $data['currency'] ?? 'NGN',
                'fee' => (string) $fee,
                'totalAmount' => (string) $totalAmount,
                'wallet' => [
                    'id' => $wallet->id,
                    'currency' => $wallet->currency,
                    'balance' => (string) $wallet->balance,
                ],
            ],
        ];
    }

    /**
     * Confirm bill payment
     */
    public function confirmBillPayment(int $userId, int $transactionId, string $pin): array
    {
        // Get transaction first for PIN verification (outside transaction)
        $transaction = Transaction::where('id', $transactionId)
            ->where('user_id', $userId)
            ->where('type', 'bill_payment')
            ->where('status', 'pending')
            ->first();

        if (!$transaction) {
            return [
                'success' => false,
                'message' => 'Transaction not found or already processed',
            ];
        }

        // Verify PIN
        $user = $transaction->user;
        if (!$user->pin) {
            return [
                'success' => false,
                'message' => 'PIN not set. Please setup your PIN first.',
            ];
        }

        if (!$this->authService->verifyPin($user, $pin)) {
            return [
                'success' => false,
                'message' => 'Invalid PIN',
            ];
        }

        return DB::transaction(function () use ($transactionId, $userId) {
            // Lock transaction to prevent double execution
            $transaction = Transaction::where('id', $transactionId)
                ->where('user_id', $userId)
                ->where('type', 'bill_payment')
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();

            if (!$transaction) {
                return [
                    'success' => false,
                    'message' => 'Transaction not found or already processed',
                ];
            }

            // Lock wallet for update to prevent race conditions
            $wallet = FiatWallet::where('user_id', $userId)
                ->where('currency', $transaction->currency)
                ->lockForUpdate()
                ->first();

            if (!$wallet) {
                return [
                    'success' => false,
                    'message' => 'Wallet not found',
                ];
            }

            // Check balance again inside transaction with lock
            if ($wallet->balance < $transaction->total_amount) {
                return [
                    'success' => false,
                    'message' => 'Insufficient balance',
                ];
            }

            // Deduct balance
            $wallet->decrement('balance', $transaction->total_amount);

            // Update transaction
            $transaction->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            // Generate recharge token for prepaid electricity
            $rechargeToken = null;
            $metadata = $transaction->metadata ?? [];
            if ($metadata['categoryCode'] === 'electricity' && $metadata['accountType'] === 'prepaid') {
                $rechargeToken = $this->generateRechargeToken();
                $metadata['rechargeToken'] = $rechargeToken;
                $transaction->update(['metadata' => $metadata]);
            }

            return [
                'success' => true,
                'data' => [
                    'id' => $transaction->id,
                    'reference' => $transaction->reference,
                    'status' => $transaction->status,
                    'amount' => (string) $transaction->amount,
                    'currency' => $transaction->currency,
                    'fee' => (string) $transaction->fee,
                    'totalAmount' => (string) $transaction->total_amount,
                    'accountNumber' => $metadata['accountNumber'] ?? null,
                    'accountName' => $metadata['accountName'] ?? null,
                    'rechargeToken' => $rechargeToken,
                    'category' => [
                        'code' => $metadata['categoryCode'] ?? null,
                        'name' => $metadata['categoryName'] ?? null,
                    ],
                    'provider' => [
                        'id' => $metadata['providerId'] ?? null,
                        'code' => $metadata['providerCode'] ?? null,
                        'name' => $metadata['providerName'] ?? null,
                    ],
                    'plan' => isset($metadata['planId']) ? [
                        'id' => $metadata['planId'],
                        'code' => $metadata['planCode'],
                        'name' => $metadata['planName'],
                    ] : null,
                    'completedAt' => $transaction->completed_at?->toDateTimeString(),
                    'createdAt' => $transaction->created_at->toDateTimeString(),
                ],
            ];
        });
    }

    /**
     * Generate recharge token for prepaid electricity
     */
    protected function generateRechargeToken(): string
    {
        return implode('-', [
            str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT),
            str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT),
            str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT),
            str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT),
        ]);
    }

    /**
     * Get user beneficiaries
     */
    public function getBeneficiaries(int $userId, ?string $categoryCode = null): \Illuminate\Database\Eloquent\Collection
    {
        $query = Beneficiary::where('user_id', $userId)
            ->where('is_active', true)
            ->with(['category', 'provider']);

        if ($categoryCode) {
            $query->whereHas('category', function($q) use ($categoryCode) {
                $q->where('code', $categoryCode);
            });
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    /**
     * Create beneficiary
     */
    public function createBeneficiary(int $userId, array $data): Beneficiary
    {
        $category = BillPaymentCategory::where('code', $data['categoryCode'])
            ->firstOrFail();

        $provider = BillPaymentProvider::where('id', $data['providerId'])
            ->where('category_id', $category->id)
            ->firstOrFail();

        // Check for duplicate
        $existing = Beneficiary::where('user_id', $userId)
            ->where('category_id', $category->id)
            ->where('provider_id', $provider->id)
            ->where('account_number', $data['accountNumber'])
            ->where('is_active', true)
            ->first();

        if ($existing) {
            throw new \Exception('Beneficiary already exists');
        }

        return Beneficiary::create([
            'user_id' => $userId,
            'category_id' => $category->id,
            'provider_id' => $provider->id,
            'name' => $data['name'] ?? null,
            'account_number' => $data['accountNumber'],
            'account_type' => $data['accountType'] ?? null,
            'is_active' => true,
        ]);
    }

    /**
     * Update beneficiary
     */
    public function updateBeneficiary(int $userId, int $beneficiaryId, array $data): Beneficiary
    {
        $beneficiary = Beneficiary::where('id', $beneficiaryId)
            ->where('user_id', $userId)
            ->firstOrFail();

        $beneficiary->update($data);

        return $beneficiary->fresh();
    }

    /**
     * Delete beneficiary (soft delete)
     */
    public function deleteBeneficiary(int $userId, int $beneficiaryId): bool
    {
        $beneficiary = Beneficiary::where('id', $beneficiaryId)
            ->where('user_id', $userId)
            ->firstOrFail();

        return $beneficiary->update(['is_active' => false]);
    }
}
