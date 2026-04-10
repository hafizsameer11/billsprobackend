<?php

namespace App\Services\VirtualCard;

use App\Models\FiatWallet;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VirtualCard;
use App\Models\VirtualCardTransaction;
use App\Services\Crypto\CryptoWalletService;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VirtualCardService
{
    public function __construct(
        protected MastercardApiClient $mastercardApiClient,
        protected WalletService $walletService,
        protected CryptoWalletService $cryptoWalletService,
    ) {}

    /**
     * Create provider-backed virtual Mastercard
     */
    public function createCard(int $userId, array $data): array
    {
        $user = User::findOrFail($userId);
        $paymentWalletType = (string) ($data['payment_wallet_type'] ?? '');
        $fiatCurrency = (string) ($data['payment_wallet_currency'] ?? 'NGN');

        $feeNgn = $this->computeCreationFeeNgn();
        $feeUsd = $this->computeCreationFeeUsd();

        if ($paymentWalletType === 'naira_wallet') {
            $wallet = $this->walletService->getFiatWallet($userId, $fiatCurrency, 'NG');
            if (! $wallet || (float) $wallet->balance < $feeNgn) {
                return [
                    'success' => false,
                    'message' => 'Insufficient Naira wallet balance for card creation fee.',
                    'status' => 400,
                ];
            }
        } elseif ($paymentWalletType === 'crypto_wallet') {
            if ($this->cryptoWalletService->getTotalCryptoBalanceInUsd($userId) + 0.0000001 < $feeUsd) {
                return [
                    'success' => false,
                    'message' => 'Insufficient crypto wallet balance for card creation fee.',
                    'status' => 400,
                ];
            }
        } else {
            return [
                'success' => false,
                'message' => 'Select naira_wallet or crypto_wallet to pay the card creation fee.',
                'status' => 422,
            ];
        }

        $accountEmail = $this->providerAccountEmail($user, $data);
        $firstname = (string) ($data['firstname'] ?? $user->first_name ?? '');
        if ($firstname === '') {
            $firstname = trim((string) (explode(' ', (string) ($user->name ?? ''), 2)[0] ?? '')) ?: 'User';
        }
        $lastname = (string) ($data['lastname'] ?? $user->last_name ?? '');
        if ($lastname === '') {
            $parts = preg_split('/\s+/', (string) ($user->name ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $lastname = count($parts) > 1 ? trim(implode(' ', array_slice($parts, 1))) : '';
            if ($lastname === '') {
                $lastname = 'Cardholder';
            }
        }

        // Mastercard reseller API: POST /api/mastercard/createcard — firstname, lastname, email
        $payload = [
            'firstname' => $firstname,
            'lastname' => $lastname,
            'email' => $accountEmail,
        ];

        try {
            $response = $this->mastercardApiClient->createMerchantMasterCard($payload);
        } catch (MastercardApiException $exception) {
            return [
                'success' => false,
                'message' => $exception->getMessage(),
                'status' => $exception->getHttpStatus(),
            ];
        }

        $resolvedProviderCardId = $this->extractProviderCardId($response);
        if (! $resolvedProviderCardId) {
            $resolvedProviderCardId = $this->resolveProviderCardIdFromList(
                (string) $payload['email'],
                (string) $payload['firstname'],
                (string) $payload['lastname']
            );
        }

        if (! $resolvedProviderCardId) {
            return [
                'success' => false,
                'message' => 'Card was issued but provider card ID could not be resolved. Please verify provider get-all endpoint payload and mapping.',
                'status' => 422,
            ];
        }

        try {
            return DB::transaction(function () use ($userId, $response, $resolvedProviderCardId, $paymentWalletType, $fiatCurrency, $feeNgn, $feeUsd, $data, $accountEmail) {
                $providerCardId = $resolvedProviderCardId;
                $cardSnapshot = $this->extractCardSnapshot($response, $providerCardId);
                $displayName = (string) ($data['card_name'] ?? $cardSnapshot['card_name']);
                $cardColor = (string) ($data['card_color'] ?? 'green');

                if ($paymentWalletType === 'naira_wallet') {
                    $wallet = FiatWallet::where('user_id', $userId)
                        ->where('currency', $fiatCurrency)
                        ->where('country_code', 'NG')
                        ->lockForUpdate()
                        ->first();
                    if (! $wallet || (float) $wallet->balance < $feeNgn) {
                        throw new \RuntimeException('Insufficient Naira wallet balance for card creation fee.');
                    }
                    $wallet->decrement('balance', $feeNgn);
                } else {
                    $deduct = $this->cryptoWalletService->deductUsdEquivalent($userId, $feeUsd);
                    if (! $deduct['success']) {
                        throw new \RuntimeException($deduct['message'] ?? 'Unable to deduct card creation fee from crypto wallet.');
                    }
                }

                $card = VirtualCard::updateOrCreate(
                    ['provider_card_id' => $providerCardId, 'user_id' => $userId],
                    [
                        'card_name' => $displayName,
                        'card_number' => $cardSnapshot['card_number'],
                        'cvv' => $cardSnapshot['cvv'],
                        'expiry_month' => $cardSnapshot['expiry_month'],
                        'expiry_year' => $cardSnapshot['expiry_year'],
                        'card_type' => 'mastercard',
                        'provider' => 'mastercard_api',
                        'provider_status' => $this->extractStatus($response),
                        'card_color' => in_array($cardColor, ['green', 'brown', 'purple'], true) ? $cardColor : 'green',
                        'currency' => 'USD',
                        'balance' => $this->extractBalance($response),
                        'is_active' => true,
                        'is_frozen' => false,
                        'billing_address_street' => $data['billing_address_street'] ?? null,
                        'billing_address_city' => $data['billing_address_city'] ?? null,
                        'billing_address_state' => $data['billing_address_state'] ?? null,
                        'billing_address_country' => $data['billing_address_country'] ?? null,
                        'billing_address_postal_code' => $data['billing_address_postal_code'] ?? null,
                        'daily_spending_limit' => $data['daily_spending_limit'] ?? null,
                        'monthly_spending_limit' => $data['monthly_spending_limit'] ?? null,
                        'daily_transaction_limit' => $data['daily_transaction_limit'] ?? null,
                        'monthly_transaction_limit' => $data['monthly_transaction_limit'] ?? null,
                        'metadata' => [
                            'source' => 'provider',
                            'provider_account_email' => (string) $accountEmail,
                            'payment_wallet_type' => $paymentWalletType,
                            'creation_fee_ngn' => $feeNgn,
                            'creation_fee_usd' => $feeUsd,
                        ],
                        'provider_payload' => $response,
                    ]
                );

                $txCurrency = $paymentWalletType === 'naira_wallet' ? $fiatCurrency : 'USD';
                $txFeeAmount = $paymentWalletType === 'naira_wallet' ? $feeNgn : $feeUsd;

                $transaction = Transaction::create([
                    'user_id' => $userId,
                    'transaction_id' => Transaction::generateTransactionId(),
                    'type' => 'card_creation',
                    'category' => 'virtual_card',
                    'status' => 'completed',
                    'currency' => $txCurrency,
                    'amount' => 0,
                    'fee' => $txFeeAmount,
                    'total_amount' => $txFeeAmount,
                    'reference' => 'CARD'.strtoupper(substr(md5(uniqid((string) $userId, true)), 0, 12)),
                    'description' => 'Virtual Mastercard creation fee',
                    'metadata' => [
                        'card_id' => $card->id,
                        'provider_card_id' => $providerCardId,
                        'payment_wallet_type' => $paymentWalletType,
                        'fee_ngn_equivalent' => $feeNgn,
                        'fee_usd_equivalent' => $feeUsd,
                    ],
                ]);

                return [
                    'success' => true,
                    'message' => $response['message'] ?? 'Virtual card created successfully',
                    'data' => [
                        'card' => $card->fresh(),
                        'provider_response' => $response,
                        'transaction' => $transaction,
                        'fee_charged' => [
                            'payment_wallet_type' => $paymentWalletType,
                            'amount' => $txFeeAmount,
                            'currency' => $txCurrency,
                        ],
                    ],
                ];
            });
        } catch (\RuntimeException $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'status' => 400,
            ];
        }
    }

    protected function computeCreationFeeNgn(): float
    {
        $usd = (float) config('virtual_card.creation_fee_usd', 3.0);
        $processing = (float) config('virtual_card.creation_processing_fee_ngn', 500.0);
        $rate = (float) config('virtual_card.usd_to_ngn_rate', 1500.0);

        return ($usd * $rate) + $processing;
    }

    protected function computeCreationFeeUsd(): float
    {
        $usd = (float) config('virtual_card.creation_fee_usd', 3.0);
        $processingNgn = (float) config('virtual_card.creation_processing_fee_ngn', 500.0);
        $rate = (float) config('virtual_card.usd_to_ngn_rate', 1500.0);

        return $usd + ($processingNgn / max($rate, 0.0001));
    }

    /**
     * Quote what will be debited from Naira or Crypto for a given card load (USD principal).
     *
     * @return array<string, mixed>
     */
    public function estimateCardFunding(float $principalUsd, string $paymentWalletType, string $fiatCurrency = 'NGN'): array
    {
        return $this->computeFundWalletCharges($principalUsd, $paymentWalletType, $fiatCurrency);
    }

    /**
     * @return array<string, mixed>
     */
    protected function computeFundWalletCharges(float $principalUsd, string $paymentWalletType, string $fiatCurrency): array
    {
        $rate = (float) config('virtual_card.usd_to_ngn_rate', 1500.0);
        $processingNgn = (float) config('virtual_card.fund_processing_fee_ngn', 500.0);
        $includeLoad = (bool) config('virtual_card.fund_include_provider_load_fee', false);
        $flat = (float) config('virtual_card.fund_load_flat_fee_usd', 1.0);
        $pct = (float) config('virtual_card.fund_load_percent', 1.0);

        $loadFeeUsd = 0.0;
        if ($includeLoad) {
            $loadFeeUsd = $flat + ($principalUsd * max($pct, 0.0) / 100.0);
        }

        $totalUsd = round($principalUsd + $loadFeeUsd, 8);

        $out = [
            'principal_usd' => round($principalUsd, 8),
            'load_fee_usd' => round($loadFeeUsd, 8),
            'total_usd' => $totalUsd,
            'processing_fee_ngn' => $paymentWalletType === 'naira_wallet' ? round($processingNgn, 2) : 0.0,
            'exchange_rate_ngn_per_usd' => $rate,
            'payment_wallet_type' => $paymentWalletType,
            'fund_include_provider_load_fee' => $includeLoad,
        ];

        if ($paymentWalletType === 'naira_wallet') {
            $out['charge_ngn'] = round($totalUsd * $rate + $processingNgn, 2);
            $out['charge_usd'] = $totalUsd;
            $out['currency'] = $fiatCurrency;
        } else {
            $out['charge_usd'] = $totalUsd;
            $out['currency'] = 'USD';
        }

        return $out;
    }

    /**
     * Fund virtual Mastercard: call provider for USD principal, then debit user's Naira or Crypto wallet.
     */
    public function fundCard(int $userId, int $cardId, array $data): array
    {
        $paymentWalletType = (string) ($data['payment_wallet_type'] ?? '');
        if (! in_array($paymentWalletType, ['naira_wallet', 'crypto_wallet'], true)) {
            return [
                'success' => false,
                'message' => 'Select naira_wallet or crypto_wallet to pay for this card load.',
                'status' => 422,
            ];
        }

        $fiatCurrency = (string) ($data['payment_wallet_currency'] ?? 'NGN');
        $principalUsd = (float) $data['amount'];

        $card = VirtualCard::where('id', $cardId)
            ->where('user_id', $userId)
            ->firstOrFail();

        if (! $card->provider_card_id) {
            return [
                'success' => false,
                'message' => 'This card is missing provider metadata and cannot be funded.',
                'status' => 400,
            ];
        }

        $user = User::findOrFail($userId);
        $charges = $this->computeFundWalletCharges($principalUsd, $paymentWalletType, $fiatCurrency);

        if ($paymentWalletType === 'naira_wallet') {
            $wallet = $this->walletService->getFiatWallet($userId, $fiatCurrency, 'NG');
            $need = (float) ($charges['charge_ngn'] ?? 0);
            if (! $wallet || (float) $wallet->balance + 0.0000001 < $need) {
                return [
                    'success' => false,
                    'message' => 'Insufficient Naira wallet balance for this card funding amount (including fees).',
                    'status' => 400,
                ];
            }
        } else {
            $needUsd = (float) ($charges['charge_usd'] ?? 0);
            if ($this->cryptoWalletService->getTotalCryptoBalanceInUsd($userId) + 0.0000001 < $needUsd) {
                return [
                    'success' => false,
                    'message' => 'Insufficient crypto wallet balance for this card funding amount (including fees).',
                    'status' => 400,
                ];
            }
        }

        $payload = [
            'email' => $this->resolveProviderAccountEmail($user, $card, $data),
            'cardid' => $card->provider_card_id,
            'amount' => $principalUsd,
        ];

        try {
            $response = $this->mastercardApiClient->fundMerchantMasterCard($payload);
        } catch (MastercardApiException $exception) {
            $context = $exception->getContext() ?? [];
            if (($context['response'] ?? null) === [] || $exception->getHttpStatus() === 404) {
                $message = 'Provider funding endpoint is not available. Check MASTERCARD_API_FUND_PATH and reseller API contract.';
            } else {
                $message = $exception->getMessage();
            }

            return [
                'success' => false,
                'message' => $message,
                'status' => $exception->getHttpStatus(),
            ];
        }

        try {
            return DB::transaction(function () use ($userId, $card, $data, $payload, $response, $paymentWalletType, $fiatCurrency, $charges, $principalUsd) {
                if ($paymentWalletType === 'naira_wallet') {
                    $wallet = FiatWallet::where('user_id', $userId)
                        ->where('currency', $fiatCurrency)
                        ->where('country_code', 'NG')
                        ->lockForUpdate()
                        ->first();
                    $chargeNgn = (float) ($charges['charge_ngn'] ?? 0);
                    if (! $wallet || (float) $wallet->balance + 0.0000001 < $chargeNgn) {
                        throw new \RuntimeException('Insufficient Naira wallet balance.');
                    }
                    $wallet->decrement('balance', $chargeNgn);
                } else {
                    $chargeUsd = (float) ($charges['charge_usd'] ?? 0);
                    $deduct = $this->cryptoWalletService->deductUsdEquivalent($userId, $chargeUsd);
                    if (! $deduct['success']) {
                        throw new \RuntimeException($deduct['message'] ?? 'Unable to deduct from crypto wallet.');
                    }
                }

                $freshCard = VirtualCard::where('id', $card->id)
                    ->where('user_id', $userId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $freshCard->update([
                    'provider_status' => $this->extractStatus($response, $freshCard->provider_status),
                    'provider_payload' => $response,
                    'balance' => $this->extractBalance($response, (float) $freshCard->balance + $principalUsd),
                ]);

                $txCurrency = $paymentWalletType === 'naira_wallet' ? $fiatCurrency : 'USD';
                $txAmount = $paymentWalletType === 'naira_wallet'
                    ? (float) ($charges['charge_ngn'] ?? 0)
                    : (float) ($charges['charge_usd'] ?? 0);

                $processingNgn = (float) ($charges['processing_fee_ngn'] ?? 0);
                $rate = (float) ($charges['exchange_rate_ngn_per_usd'] ?? 1);
                $feeUsdReporting = (float) ($charges['load_fee_usd'] ?? 0);
                if ($paymentWalletType === 'naira_wallet' && $processingNgn > 0) {
                    $feeUsdReporting += $processingNgn / max($rate, 0.0001);
                }

                $transaction = Transaction::create([
                    'user_id' => $userId,
                    'transaction_id' => Transaction::generateTransactionId(),
                    'type' => 'card_funding',
                    'category' => 'virtual_card',
                    'status' => 'completed',
                    'currency' => $txCurrency,
                    'amount' => $txAmount,
                    'fee' => 0,
                    'total_amount' => $txAmount,
                    'reference' => 'FUND'.strtoupper(substr(md5(uniqid((string) $userId, true)), 0, 12)),
                    'description' => 'Virtual card load: $'.number_format($principalUsd, 2).' USD from '
                        .($paymentWalletType === 'naira_wallet' ? 'Naira' : 'Crypto').' wallet',
                    'metadata' => [
                        'card_id' => $freshCard->id,
                        'provider_card_id' => $freshCard->provider_card_id,
                        'principal_usd' => $principalUsd,
                        'wallet_charge' => $charges,
                        'payment_wallet_type' => $paymentWalletType,
                        'provider_payload' => $response,
                    ],
                ]);

                VirtualCardTransaction::create([
                    'virtual_card_id' => $freshCard->id,
                    'user_id' => $userId,
                    'transaction_id' => $transaction->id,
                    'provider_transaction_id' => (string) ($this->extractProviderReference($response) ?? ''),
                    'type' => 'fund',
                    'status' => 'completed',
                    'currency' => 'USD',
                    'amount' => $principalUsd,
                    'fee' => round($feeUsdReporting, 8),
                    'total_amount' => $principalUsd,
                    'payment_wallet_type' => $paymentWalletType,
                    'payment_wallet_currency' => $paymentWalletType === 'naira_wallet' ? $fiatCurrency : 'USD',
                    'exchange_rate' => $paymentWalletType === 'naira_wallet' ? $rate : 1,
                    'reference' => $transaction->reference,
                    'description' => 'Card funded from app wallet',
                    'metadata' => [
                        'wallet_charge' => $charges,
                    ],
                    'provider_payload' => $response,
                ]);

                return [
                    'success' => true,
                    'message' => $response['message'] ?? 'Card funded successfully',
                    'data' => [
                        'card' => $freshCard->fresh(),
                        'transaction' => $transaction,
                        'amount_funded_usd' => $principalUsd,
                        'wallet_charged' => $charges,
                        'provider_response' => $response,
                        'funding_payload' => $payload,
                    ],
                ];
            });
        } catch (\RuntimeException $e) {
            Log::critical('Virtual card funded at provider but wallet bookkeeping failed', [
                'user_id' => $userId,
                'card_id' => $card->id,
                'principal_usd' => $principalUsd,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'The card issuer accepted the load, but updating your wallet failed. Please contact support immediately.',
                'status' => 500,
            ];
        }
    }

    /**
     * Card withdrawal not supported by the current provider.
     */
    public function withdrawFromCard(int $userId, int $cardId, array $data): array
    {
        return [
            'success' => false,
            'message' => 'Card withdrawal is not supported for the current virtual card provider.',
        ];
    }

    /**
     * Freeze/Unfreeze card via provider.
     */
    public function toggleFreeze(int $userId, int $cardId, bool $freeze = true): array
    {
        $card = VirtualCard::where('id', $cardId)
            ->where('user_id', $userId)
            ->firstOrFail();

        if (! $card->provider_card_id) {
            return [
                'success' => false,
                'message' => 'This card is missing provider metadata.',
            ];
        }

        $user = User::findOrFail($userId);
        $payload = [
            'email' => $this->resolveProviderAccountEmail($user, $card),
            'cardid' => $card->provider_card_id,
        ];

        try {
            $response = $freeze
                ? $this->mastercardApiClient->blockMerchantMasterCard($payload)
                : $this->mastercardApiClient->unblockMerchantMasterCard($payload);
        } catch (MastercardApiException $exception) {
            return [
                'success' => false,
                'message' => $exception->getMessage(),
            ];
        }

        $card->update([
            'is_frozen' => $freeze,
            'provider_status' => $this->extractStatus($response, $card->provider_status),
            'provider_payload' => $response,
        ]);

        return [
            'success' => true,
            'message' => $response['message'] ?? ($freeze ? 'Card frozen successfully' : 'Card unfrozen successfully'),
            'data' => [
                'card' => $card->fresh(),
                'is_frozen' => $freeze,
                'provider_response' => $response,
            ],
        ];
    }

    /**
     * Get user's virtual cards
     */
    public function getUserCards(int $userId): \Illuminate\Database\Eloquent\Collection
    {
        $user = User::findOrFail($userId);

        try {
            $response = $this->mastercardApiClient->getMerchantMasterCards(['email' => $user->email]);
            $cards = $this->extractCardsFromListResponse($response);

            foreach ($cards as $providerCard) {
                $providerCardId = (string) ($providerCard['cardid'] ?? $providerCard['id'] ?? '');
                if ($providerCardId !== '') {
                    $existingCard = VirtualCard::where('provider_card_id', $providerCardId)
                        ->where('user_id', $userId)
                        ->first();
                    $snapshot = $this->extractCardSnapshot(['data' => $providerCard], $providerCardId);
                    VirtualCard::updateOrCreate(
                        ['provider_card_id' => $providerCardId, 'user_id' => $userId],
                        [
                            'card_name' => $snapshot['card_name'],
                            'card_number' => $snapshot['card_number'],
                            'cvv' => $snapshot['cvv'],
                            'expiry_month' => $snapshot['expiry_month'],
                            'expiry_year' => $snapshot['expiry_year'],
                            'provider' => 'mastercard_api',
                            'provider_status' => (string) ($providerCard['status'] ?? 'active'),
                            'currency' => 'USD',
                            'balance' => $this->extractBalance(['data' => $providerCard], (float) ($existingCard?->balance ?? 0)),
                            'provider_payload' => $providerCard,
                        ]
                    );
                }
            }
        } catch (MastercardApiException) {
            // Fall back to cached cards if provider fails.
        }

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
        $card = VirtualCard::where('id', $cardId)
            ->where('user_id', $userId)
            ->first();

        if (! $card || ! $card->provider_card_id) {
            return $card;
        }

        $user = User::findOrFail($userId);
        try {
            $response = $this->mastercardApiClient->getMerchantMasterCard([
                'email' => $user->email,
                'cardid' => $card->provider_card_id,
            ]);

            $snapshot = $this->extractCardSnapshot($response, $card->provider_card_id);
            $card->update([
                'card_name' => $snapshot['card_name'],
                'card_number' => $snapshot['card_number'],
                'cvv' => $snapshot['cvv'],
                'expiry_month' => $snapshot['expiry_month'],
                'expiry_year' => $snapshot['expiry_year'],
                'balance' => $this->extractBalance($response, (float) $card->balance),
                'provider_status' => $this->extractStatus($response, $card->provider_status),
                'provider_payload' => $response,
            ]);
        } catch (MastercardApiException) {
            // return cached card
        }

        return $card->fresh();
    }

    /**
     * Get card transactions
     */
    public function getCardTransactions(int $userId, int $cardId, int $limit = 50): \Illuminate\Database\Eloquent\Collection
    {
        $card = VirtualCard::where('id', $cardId)->where('user_id', $userId)->first();
        if ($card && $card->provider_card_id) {
            $user = User::findOrFail($userId);
            $txPath = (string) config('mastercard.endpoints.merchant_master_transactions');
            if ($txPath !== '') {
                try {
                    $providerResponse = $this->mastercardApiClient->merchantMasterTransactions([
                        'email' => $user->email,
                        'cardid' => $card->provider_card_id,
                    ]);
                    $providerTransactions = $providerResponse['data'] ?? [];
                    if (is_array($providerTransactions)) {
                        foreach ($providerTransactions as $providerTransaction) {
                            if (! is_array($providerTransaction)) {
                                continue;
                            }
                            VirtualCardTransaction::updateOrCreate(
                                [
                                    'virtual_card_id' => $cardId,
                                    'provider_transaction_id' => (string) ($providerTransaction['id'] ?? $providerTransaction['reference'] ?? ''),
                                ],
                                [
                                    'user_id' => $userId,
                                    'type' => (string) ($providerTransaction['type'] ?? 'provider'),
                                    'status' => (string) ($providerTransaction['status'] ?? 'completed'),
                                    'currency' => (string) ($providerTransaction['currency'] ?? 'USD'),
                                    'amount' => (float) ($providerTransaction['amount'] ?? 0),
                                    'fee' => (float) ($providerTransaction['fee'] ?? 0),
                                    'total_amount' => (float) ($providerTransaction['total_amount'] ?? $providerTransaction['amount'] ?? 0),
                                    'reference' => (string) ($providerTransaction['reference'] ?? null),
                                    'description' => (string) ($providerTransaction['description'] ?? 'Provider card transaction'),
                                    'provider_payload' => $providerTransaction,
                                ]
                            );
                        }
                    }
                } catch (MastercardApiException) {
                    // return local cache
                }
            }
        }

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
     * Terminate card via provider.
     */
    public function terminateCard(int $userId, int $cardId): array
    {
        $card = VirtualCard::where('id', $cardId)->where('user_id', $userId)->firstOrFail();
        if (! $card->provider_card_id) {
            return [
                'success' => false,
                'message' => 'This card is missing provider metadata.',
            ];
        }

        $user = User::findOrFail($userId);
        try {
            $response = $this->mastercardApiClient->terminateMerchantMasterCard([
                'email' => $user->email,
                'cardid' => $card->provider_card_id,
            ]);
        } catch (MastercardApiException $exception) {
            return [
                'success' => false,
                'message' => $exception->getMessage(),
            ];
        }

        $card->update([
            'is_active' => false,
            'provider_status' => $this->extractStatus($response, 'terminated'),
            'provider_payload' => $response,
        ]);

        return [
            'success' => true,
            'message' => $response['message'] ?? 'Card terminated successfully',
            'data' => [
                'card' => $card->fresh(),
                'provider_response' => $response,
            ],
        ];
    }

    public function check3ds(int $userId, int $cardId): array
    {
        return $this->invokeProviderCardAction($userId, $cardId, fn (array $payload) => $this->mastercardApiClient->check3ds($payload));
    }

    public function checkWalletOtp(int $userId, int $cardId): array
    {
        return $this->invokeProviderCardAction($userId, $cardId, fn (array $payload) => $this->mastercardApiClient->checkWallet($payload));
    }

    public function approve3ds(int $userId, int $cardId, string $eventId): array
    {
        return $this->invokeProviderCardAction(
            $userId,
            $cardId,
            fn (array $payload) => $this->mastercardApiClient->approve3ds(array_merge($payload, ['eventId' => $eventId]))
        );
    }

    protected function invokeProviderCardAction(int $userId, int $cardId, callable $apiAction): array
    {
        $card = VirtualCard::where('id', $cardId)->where('user_id', $userId)->firstOrFail();
        if (! $card->provider_card_id) {
            return ['success' => false, 'message' => 'This card is missing provider metadata.'];
        }

        $user = User::findOrFail($userId);
        $payload = ['email' => $this->resolveProviderAccountEmail($user, $card), 'cardid' => $card->provider_card_id];
        try {
            $response = $apiAction($payload);
        } catch (MastercardApiException $exception) {
            return ['success' => false, 'message' => $exception->getMessage()];
        }

        $card->update([
            'provider_status' => $this->extractStatus($response, $card->provider_status),
            'provider_payload' => $response,
        ]);

        return [
            'success' => true,
            'message' => $response['message'] ?? 'Provider action completed successfully',
            'data' => [
                'card' => $card->fresh(),
                'provider_response' => $response,
            ],
        ];
    }

    protected function resolveProviderCardIdFromList(string $userEmail, string $firstName, string $lastName): ?string
    {
        try {
            $listResponse = $this->mastercardApiClient->getMerchantMasterCards([
                'email' => $userEmail,
            ]);
        } catch (MastercardApiException) {
            return null;
        }

        $cards = $this->extractCardsFromListResponse($listResponse);
        if ($cards === []) {
            return null;
        }

        $fullName = trim($firstName.' '.$lastName);

        // Prefer a name match first.
        foreach ($cards as $card) {
            if (! is_array($card)) {
                continue;
            }
            $nameOnCard = (string) ($card['nameoncard'] ?? $card['name'] ?? '');
            $candidateId = (string) ($card['cardid'] ?? $card['card_id'] ?? $card['id'] ?? '');
            if ($candidateId !== '' && $nameOnCard !== '' && strcasecmp($nameOnCard, $fullName) === 0) {
                return $candidateId;
            }
        }

        // Fallback: last card in list with a valid ID.
        $cards = array_values(array_filter($cards, static fn ($card) => is_array($card)));
        for ($i = count($cards) - 1; $i >= 0; $i--) {
            $candidateId = (string) ($cards[$i]['cardid'] ?? $cards[$i]['card_id'] ?? $cards[$i]['id'] ?? '');
            if ($candidateId !== '') {
                return $candidateId;
            }
        }

        return null;
    }

    protected function extractProviderCardId(array $response): ?string
    {
        $candidates = [
            data_get($response, 'data.cardid'),
            data_get($response, 'data.card_id'),
            data_get($response, 'data.id'),
            data_get($response, 'cardid'),
            data_get($response, 'card_id'),
            data_get($response, 'id'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    protected function extractProviderReference(array $response): ?string
    {
        $candidates = [
            data_get($response, 'data.reference'),
            data_get($response, 'reference'),
            data_get($response, 'data.transaction_id'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    protected function extractCardSnapshot(array $response, string $providerCardId): array
    {
        $rawNumber = (string) (data_get($response, 'data.pan') ?? data_get($response, 'data.card_number') ?? '');
        $digits = preg_replace('/\D+/', '', $rawNumber ?? '') ?? '';
        if ($digits === '') {
            $digits = substr(preg_replace('/\D+/', '', str_pad((string) crc32($providerCardId), 16, '0', STR_PAD_LEFT)) ?? '', 0, 16);
        }
        $digits = str_pad(substr($digits, 0, 16), 16, '0', STR_PAD_RIGHT);

        return [
            'card_name' => (string) (data_get($response, 'data.nameoncard') ?? data_get($response, 'data.name') ?? 'Merchant Card'),
            'card_number' => $digits,
            'cvv' => str_pad((string) (data_get($response, 'data.cvv') ?? '000'), 3, '0', STR_PAD_LEFT),
            'expiry_month' => str_pad((string) (data_get($response, 'data.expiry_month') ?? date('m')), 2, '0', STR_PAD_LEFT),
            'expiry_year' => (string) (data_get($response, 'data.expiry_year') ?? date('Y', strtotime('+2 years'))),
        ];
    }

    protected function extractBalance(array $response, ?float $fallback = 0): float
    {
        $candidates = [
            data_get($response, 'data.balance'),
            data_get($response, 'data.available_balance'),
            data_get($response, 'data.availableBalance'),
            data_get($response, 'data.card_balance'),
            data_get($response, 'data.cardBalance'),
            data_get($response, 'data.amount'),
            data_get($response, 'balance'),
            data_get($response, 'available_balance'),
            data_get($response, 'availableBalance'),
            data_get($response, 'card_balance'),
            data_get($response, 'cardBalance'),
            data_get($response, 'amount'),
            $fallback,
        ];

        $value = $fallback;
        foreach ($candidates as $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }
            $parsed = is_string($candidate)
                ? (float) preg_replace('/[^\d\.\-]/', '', $candidate)
                : (float) $candidate;

            if (! is_nan($parsed)) {
                $value = $parsed;
                break;
            }
        }

        return (float) $value;
    }

    protected function extractStatus(array $response, ?string $fallback = 'active'): string
    {
        return (string) (data_get($response, 'data.status') ?? data_get($response, 'status') ?? $fallback ?? 'active');
    }

    protected function extractCardsFromListResponse(array $response): array
    {
        $cards = data_get($response, 'data');
        if (! is_array($cards)) {
            return [];
        }

        $isAssoc = array_keys($cards) !== range(0, count($cards) - 1);
        if ($isAssoc) {
            return [$cards];
        }

        return array_values(array_filter($cards, 'is_array'));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function providerAccountEmail(User $user, array $data): string
    {
        return (string) ($data['email'] ?? $data['useremail'] ?? $user->email);
    }

    /**
     * Resolve provider email for an existing card action.
     *
     * Preference order:
     * 1) explicit request overrides
     * 2) stored card metadata provider_account_email
     * 3) authenticated user email
     *
     * @param array<string, mixed> $requestData
     */
    protected function resolveProviderAccountEmail(User $user, VirtualCard $card, array $requestData = []): string
    {
        $fromRequest = (string) ($requestData['email'] ?? $requestData['useremail'] ?? '');
        if ($fromRequest !== '') {
            return $fromRequest;
        }

        $metaEmail = (string) data_get($card->metadata ?? [], 'provider_account_email', '');
        if ($metaEmail !== '') {
            return $metaEmail;
        }

        return (string) $user->email;
    }
}
