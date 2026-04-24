<?php

namespace App\Services\VirtualCard;

use App\Models\ApplicationLog;
use App\Models\FiatWallet;
use App\Models\PlatformRate;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VirtualCard;
use App\Models\VirtualCardProviderWebhookEvent;
use App\Models\VirtualCardTransaction;
use App\Services\Crypto\CryptoWalletService;
use App\Services\Platform\PlatformRateResolver;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VirtualCardService
{
    public function __construct(
        protected MastercardApiClient $mastercardApiClient,
        protected WalletService $walletService,
        protected CryptoWalletService $cryptoWalletService,
        protected PlatformRateResolver $platformRates,
    ) {}

    /**
     * Pagocards-supported billing columns (same for all cards).
     *
     * @return array{
     *     billing_address_street: string,
     *     billing_address_city: string,
     *     billing_address_state: string,
     *     billing_address_country: string,
     *     billing_address_postal_code: string
     * }
     */
    public function pagocardsProgramBillingColumns(): array
    {
        $b = config('virtual_card.program_billing', []);

        return [
            'billing_address_street' => (string) ($b['billing_address_street'] ?? '128 city road'),
            'billing_address_city' => (string) ($b['billing_address_city'] ?? 'london'),
            'billing_address_state' => (string) ($b['billing_address_state'] ?? 'london'),
            'billing_address_country' => (string) ($b['billing_address_country'] ?? 'GB'),
            'billing_address_postal_code' => (string) ($b['billing_address_postal_code'] ?? 'ec1v2nx'),
        ];
    }

    /**
     * Shape returned by GET /virtual-cards/{id}/billing-address (always program address).
     *
     * @return array{street: string, city: string, state: string, country: string, postal_code: string}
     */
    public function programBillingAddressForApp(): array
    {
        $b = $this->pagocardsProgramBillingColumns();

        return [
            'street' => $b['billing_address_street'],
            'city' => $b['billing_address_city'],
            'state' => $b['billing_address_state'],
            'country' => $b['billing_address_country'],
            'postal_code' => $b['billing_address_postal_code'],
        ];
    }

    /**
     * Persist program billing on the card row when any field is missing (legacy rows).
     */
    public function ensurePagocardsBillingPersisted(VirtualCard $card): void
    {
        $p = $this->pagocardsProgramBillingColumns();
        $empty = static fn (?string $v): bool => trim((string) ($v ?? '')) === '';
        if (
            $empty($card->billing_address_street)
            || $empty($card->billing_address_city)
            || $empty($card->billing_address_state)
            || $empty($card->billing_address_country)
            || $empty($card->billing_address_postal_code)
        ) {
            $card->update($p);
        }
    }

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
            ApplicationLog::warning('virtual_card', 'virtual_card.create.provider_exception', [
                'user_id' => $userId,
                'http_status' => $exception->getHttpStatus(),
                'message' => $exception->getMessage(),
                'context' => $exception->getContext(),
            ]);

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
            ApplicationLog::warning('virtual_card', 'virtual_card.create.provider_card_id_unresolved', [
                'user_id' => $userId,
                'provider_account_email' => $accountEmail,
                'provider_response' => $this->sanitizeProviderPayloadForLog($response),
            ]);

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
                $programBilling = $this->pagocardsProgramBillingColumns();

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
                        'card_color' => in_array($cardColor, $this->allowedCardColors(), true) ? $cardColor : 'green',
                        'currency' => 'USD',
                        'balance' => $this->extractBalance($response),
                        'is_active' => true,
                        'is_frozen' => false,
                        ...$programBilling,
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

                ApplicationLog::info('virtual_card', 'virtual_card.create.completed', [
                    'user_id' => $userId,
                    'virtual_card_id' => $card->id,
                    'provider_card_id' => $providerCardId,
                    'provider_status' => $card->provider_status,
                    'card_balance_usd' => (float) $card->balance,
                    'payment_wallet_type' => $paymentWalletType,
                    'fee_charged_amount' => $txFeeAmount,
                    'fee_charged_currency' => $txCurrency,
                    'ledger_transaction_id' => $transaction->transaction_id,
                    'provider_message' => $response['message'] ?? null,
                    'provider_response' => $this->sanitizeProviderPayloadForLog($response),
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
            ApplicationLog::warning('virtual_card', 'virtual_card.create.fee_or_persist_failed', [
                'user_id' => $userId,
                'provider_card_id' => $resolvedProviderCardId ?? null,
                'message' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
                'status' => 400,
            ];
        }
    }

    /**
     * Strip PCI-sensitive fields before writing provider JSON to {@see ApplicationLog}.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function sanitizeProviderPayloadForLog(array $payload): array
    {
        $redactExact = [
            'pan', 'card_number', 'cardnumber', 'cvv', 'cvv2', 'cvc', 'cvc2',
            'secretkey', 'secret_key', 'privatekey', 'private_key', 'password', 'accesstoken',
        ];

        $walker = function (mixed $node) use (&$walker, $redactExact): mixed {
            if (! is_array($node)) {
                return $node;
            }
            $out = [];
            foreach ($node as $key => $value) {
                $lk = strtolower((string) $key);
                if (in_array($lk, $redactExact, true)
                    || str_contains($lk, 'cvv')
                    || str_contains($lk, 'cvc')
                    || str_ends_with($lk, 'pan')) {
                    $out[$key] = '***';

                    continue;
                }
                $out[$key] = is_array($value) ? $walker($value) : $value;
            }

            return $out;
        };

        return $walker($payload);
    }

    /**
     * Card creation is billed as: admin USD fee × NGN/USD rate (no extra NGN processing line).
     * Admin: Platform rate `virtual_card` / `creation` — `fee_usd`, `exchange_rate_ngn_per_usd`.
     */
    public function getCreationFeeQuote(): array
    {
        $feeUsd = $this->resolveCreationFeeUsd();
        $rate = $this->resolveCreationRateNgnPerUsd();
        $feeNgn = round($feeUsd * $rate, 2);

        $rFund = $this->platformRates->findVirtualCard('fund');
        $fundRate = $rFund && $rFund->exchange_rate_ngn_per_usd !== null
            ? (float) $rFund->exchange_rate_ngn_per_usd
            : (float) config('virtual_card.usd_to_ngn_rate', 1500.0);
        $fundProcessingNgn = $this->resolveFundFlatProcessingFeeNgn($rFund, $fundRate);
        $includeLoad = (bool) config('virtual_card.fund_include_provider_load_fee', false);
        $fundFlatUsd = (float) config('virtual_card.fund_load_flat_fee_usd', 1.0);
        $fundPct = $rFund && $rFund->percentage_fee !== null
            ? (float) $rFund->percentage_fee
            : (float) config('virtual_card.fund_load_percent', 1.0);

        return [
            'fee_usd' => $feeUsd,
            'exchange_rate_ngn_per_usd' => $rate,
            'fee_ngn' => $feeNgn,
            'card_program' => [
                'billspro_spend_fee_percent' => 0.0,
                'fund_include_provider_load_fee' => $includeLoad,
                'fund_load_flat_fee_usd' => $fundFlatUsd,
                'fund_load_percent' => $fundPct,
                'fund_processing_fee_ngn' => round($fundProcessingNgn, 2),
                'fund_exchange_rate_ngn_per_usd' => max(0.0001, $fundRate),
            ],
        ];
    }

    /**
     * @return list<string>
     */
    protected function allowedCardColors(): array
    {
        return ['green', 'black', 'purple', 'red', 'blue', 'brown'];
    }

    protected function resolveCreationFeeUsd(): float
    {
        $r = $this->platformRates->findVirtualCard('creation');
        if ($r && $r->fee_usd !== null) {
            return max(0.0, (float) $r->fee_usd);
        }

        return max(0.0, (float) config('virtual_card.creation_fee_usd', 3.0));
    }

    protected function resolveCreationRateNgnPerUsd(): float
    {
        $r = $this->platformRates->findVirtualCard('creation');
        if ($r && $r->exchange_rate_ngn_per_usd !== null) {
            return max(0.0001, (float) $r->exchange_rate_ngn_per_usd);
        }

        return max(0.0001, (float) config('virtual_card.usd_to_ngn_rate', 1500.0));
    }

    /**
     * Flat BillsPro processing fee for card loads (added to Naira debit): prefer admin `fee_usd` × NGN/USD;
     * legacy rows may only have `fixed_fee_ngn`.
     */
    protected function resolveFundFlatProcessingFeeNgn(?PlatformRate $r, float $ngnPerUsd): float
    {
        $ngnPerUsd = max(0.0001, $ngnPerUsd);
        if ($r) {
            if ($r->fee_usd !== null) {
                return round(max(0.0, (float) $r->fee_usd) * $ngnPerUsd, 2);
            }
            if ((float) $r->fixed_fee_ngn > 0.0) {
                return round((float) $r->fixed_fee_ngn, 2);
            }
        }

        return round((float) config('virtual_card.fund_processing_fee_ngn', 500.0), 2);
    }

    /**
     * USD amount shown as "card funding fee" in apps — mirrors admin `virtual_card` / `fund` `fee_usd`.
     * Legacy rows may only have `fixed_fee_ngn`; derive a display USD using the fund exchange rate.
     */
    protected function resolveCardFundingFeeUsdForDisplay(?PlatformRate $r, float $ngnPerUsd): float
    {
        $ngnPerUsd = max(0.0001, $ngnPerUsd);
        if ($r && $r->fee_usd !== null) {
            return max(0.0, (float) $r->fee_usd);
        }
        if ($r && (float) $r->fixed_fee_ngn > 0.0) {
            return round((float) $r->fixed_fee_ngn / $ngnPerUsd, 8);
        }

        return max(0.0, (float) config('virtual_card.fund_load_flat_fee_usd', 1.0));
    }

    protected function computeCreationFeeNgn(): float
    {
        return round($this->resolveCreationFeeUsd() * $this->resolveCreationRateNgnPerUsd(), 2);
    }

    protected function computeCreationFeeUsd(): float
    {
        return $this->resolveCreationFeeUsd();
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
        $r = $this->platformRates->findVirtualCard('fund');
        $rate = $r && $r->exchange_rate_ngn_per_usd !== null
            ? (float) $r->exchange_rate_ngn_per_usd
            : (float) config('virtual_card.usd_to_ngn_rate', 1500.0);
        $processingNgn = $this->resolveFundFlatProcessingFeeNgn($r, $rate);
        $includeLoad = (bool) config('virtual_card.fund_include_provider_load_fee', false);
        $flat = (float) config('virtual_card.fund_load_flat_fee_usd', 1.0);
        $pct = $r && $r->percentage_fee !== null
            ? (float) $r->percentage_fee
            : (float) config('virtual_card.fund_load_percent', 1.0);

        $loadFeeUsd = 0.0;
        if ($includeLoad) {
            $loadFeeUsd = $flat + ($principalUsd * max($pct, 0.0) / 100.0);
        }

        $totalUsd = round($principalUsd + $loadFeeUsd, 8);

        $cardFundingFeeUsdDisplay = $this->resolveCardFundingFeeUsdForDisplay($r, $rate);

        $out = [
            'principal_usd' => round($principalUsd, 8),
            'load_fee_usd' => round($loadFeeUsd, 8),
            'total_usd' => $totalUsd,
            'processing_fee_ngn' => $paymentWalletType === 'naira_wallet' ? round($processingNgn, 2) : 0.0,
            'exchange_rate_ngn_per_usd' => $rate,
            'payment_wallet_type' => $paymentWalletType,
            'fund_include_provider_load_fee' => $includeLoad,
            /** Shown in apps as “card funding fee” (USD); from admin `fee_usd` × rate → `processing_fee_ngn` for Naira. */
            'card_funding_fee_usd' => $cardFundingFeeUsdDisplay,
            'billspro_transaction_fee_percent' => 0.0,
            'billspro_transaction_fee_ngn' => 0.0,
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

        $this->ensurePagocardsBillingPersisted($card);
        $card->refresh();

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

            ApplicationLog::warning('virtual_card', 'virtual_card.fund_provider_failed', [
                'endpoint_key' => $context['endpoint_key'] ?? 'merchant_master_fund',
                'user_id' => $userId,
                'virtual_card_id' => $cardId,
                'provider_card_id' => $card->provider_card_id,
                'http_status' => $exception->getHttpStatus(),
                'user_message' => $message,
                'provider_message' => $exception->getMessage(),
                'merchant_base_url' => config('mastercard.merchant_base_url'),
                'fund_path' => config('mastercard.endpoints.merchant_master_fund'),
                'resolved_fund_url' => rtrim((string) config('mastercard.merchant_base_url'), '/')
                    .'/'.ltrim((string) config('mastercard.endpoints.merchant_master_fund'), '/'),
                'provider_url' => $context['url'] ?? null,
                'mastercard_context' => $context,
            ]);

            return [
                'success' => false,
                'message' => $message,
                'status' => $exception->getHttpStatus(),
            ];
        }

        try {
            $result = DB::transaction(function () use ($userId, $card, $payload, $response, $paymentWalletType, $fiatCurrency, $charges, $principalUsd) {
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
                        'payment_wallet_currency' => $paymentWalletType === 'naira_wallet' ? $fiatCurrency : 'USD',
                        'exchange_rate_ngn_per_usd' => $rate,
                        'card_funding_fee_usd' => (float) ($charges['card_funding_fee_usd'] ?? config('virtual_card.fund_load_flat_fee_usd', 1.0)),
                        'billspro_transaction_fee_percent' => 0.0,
                        'total_charge_ngn' => $paymentWalletType === 'naira_wallet' ? (float) ($charges['charge_ngn'] ?? 0) : null,
                        'total_charge_usd' => $paymentWalletType === 'crypto_wallet' ? (float) ($charges['charge_usd'] ?? 0) : null,
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

            if (($result['success'] ?? false) === true) {
                $this->syncCardBalanceWithProviderDetails($userId, (int) $card->id);
                $refreshed = VirtualCard::where('id', $card->id)->where('user_id', $userId)->first();
                if ($refreshed && isset($result['data']) && is_array($result['data'])) {
                    $result['data']['card'] = $refreshed;
                }
            }

            return $result;
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
     * Re-fetch {@see getMerchantMasterCard} after a successful fund. The fund response often lists a
     * partial field as `balance` while getcarddetails exposes the full card USD balance Pagocards shows.
     */
    protected function syncCardBalanceWithProviderDetails(int $userId, int $cardId): void
    {
        $card = VirtualCard::where('id', $cardId)->where('user_id', $userId)->first();
        if (! $card || ! $card->provider_card_id) {
            return;
        }

        $user = User::find($userId);
        if (! $user) {
            return;
        }

        try {
            $response = $this->mastercardApiClient->getMerchantMasterCard([
                'email' => $this->resolveProviderAccountEmail($user, $card),
                'cardid' => $card->provider_card_id,
            ]);
            $card->update([
                'balance' => $this->extractBalance($response, (float) $card->balance),
                'provider_payload' => $response,
                'provider_status' => $this->extractStatus($response, $card->provider_status),
            ]);
            $this->syncProviderTransactions($userId, $cardId, $response);
        } catch (MastercardApiException) {
            // keep balance from fund step
        }
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
                'email' => $this->resolveProviderAccountEmail($user, $card),
                'cardid' => $card->provider_card_id,
            ]);

            Log::channel('database')->info('[virtual_card] get_card_details complete provider response', [
                'user_id' => $userId,
                'virtual_card_id' => $cardId,
                'provider_card_id' => $card->provider_card_id,
                'provider_response' => $response,
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

            // Provider may embed recent card transactions in card-details response.
            $this->syncProviderTransactions($userId, $cardId, $response);
        } catch (MastercardApiException) {
            // return cached card
        }

        return $card->fresh();
    }

    /**
     * Get card transactions (virtual_card_transactions + any main ledger rows for this card not yet linked).
     *
     * Pagocards embeds recent card activity in **getcarddetails** (`POST /mastercard/getcarddetails`).
     * We sync from that response first; optional `getcardtransactions` is only used when enabled and
     * does not replace card-details data.
     */
    public function getCardTransactions(int $userId, int $cardId, int $limit = 50): array
    {
        $card = VirtualCard::where('id', $cardId)->where('user_id', $userId)->first();
        if ($card && $card->provider_card_id) {
            $user = User::findOrFail($userId);
            $email = $this->resolveProviderAccountEmail($user, $card);

            try {
                $cardDetailsResponse = $this->mastercardApiClient->getMerchantMasterCard([
                    'email' => $email,
                    'cardid' => $card->provider_card_id,
                ]);
                Log::channel('database')->info('[virtual_card] get_card_details (tx sync) complete provider response', [
                    'user_id' => $userId,
                    'virtual_card_id' => $cardId,
                    'provider_card_id' => $card->provider_card_id,
                    'provider_response' => $cardDetailsResponse,
                ]);
                $this->syncProviderTransactions($userId, $cardId, $cardDetailsResponse);
            } catch (MastercardApiException) {
                // return local cache below
            }

            if (config('mastercard.use_dedicated_transactions_endpoint', false)) {
                try {
                    $providerResponse = $this->mastercardApiClient->merchantMasterTransactions([
                        'email' => $email,
                        'cardid' => $card->provider_card_id,
                    ]);
                    Log::channel('database')->info('[virtual_card] merchant_master_transactions supplemental sync', [
                        'user_id' => $userId,
                        'virtual_card_id' => $cardId,
                        'provider_card_id' => $card->provider_card_id,
                        'provider_response' => $providerResponse,
                    ]);
                    $this->syncProviderTransactions($userId, $cardId, $providerResponse);
                } catch (MastercardApiException) {
                    // optional endpoint missing or failing — card-details sync above is enough
                }
            }
        }

        $vcRows = VirtualCardTransaction::where('virtual_card_id', $cardId)
            ->where('user_id', $userId)
            ->with(['transaction'])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        $coveredMainIds = $vcRows->pluck('transaction_id')->filter()->unique()->values();

        $extraMain = Transaction::query()
            ->where('user_id', $userId)
            ->whereIn('type', ['card_funding', 'card_withdrawal'])
            ->where(function ($q) use ($cardId) {
                $q->where('metadata->card_id', $cardId)
                    ->orWhere('metadata->virtual_card_id', $cardId);
            })
            ->when($coveredMainIds->isNotEmpty(), fn ($q) => $q->whereNotIn('id', $coveredMainIds))
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        $fromVc = $vcRows->map(fn (VirtualCardTransaction $row) => $row->toArray())->all();
        $fromLedger = $extraMain->map(fn (Transaction $t) => $this->formatMainLedgerRowForCardTransactions($t, $cardId))->all();

        $merged = collect(array_merge($fromVc, $fromLedger))
            ->sortByDesc(fn (array $row) => $row['created_at'] ?? '')
            ->values()
            ->take($limit)
            ->all();

        return $merged;
    }

    /**
     * Shape a main {@see Transaction} row like {@see VirtualCardTransaction} for the mobile card feed.
     */
    protected function formatMainLedgerRowForCardTransactions(Transaction $t, int $cardId): array
    {
        $type = match ($t->type) {
            'card_funding' => 'fund',
            'card_withdrawal' => 'withdraw',
            default => $t->type,
        };

        $md = is_array($t->metadata) ? $t->metadata : [];

        return [
            'id' => -1 * (int) $t->id,
            'virtual_card_id' => $cardId,
            'user_id' => $t->user_id,
            'transaction_id' => $t->id,
            'provider_transaction_id' => null,
            'type' => $type,
            'status' => $t->status,
            'currency' => $t->currency,
            'amount' => (float) $t->amount,
            'fee' => (float) $t->fee,
            'total_amount' => (float) $t->total_amount,
            'payment_wallet_type' => $md['payment_wallet_type'] ?? null,
            'payment_wallet_currency' => $md['payment_wallet_currency'] ?? null,
            'exchange_rate' => isset($md['exchange_rate_ngn_per_usd']) ? (float) $md['exchange_rate_ngn_per_usd'] : null,
            'reference' => $t->reference,
            'description' => $t->description,
            'metadata' => array_merge($md, [
                'virtual_card_id' => $cardId,
                'source' => 'ledger_transaction',
                'ledger_transaction_id' => $t->transaction_id,
            ]),
            'provider_payload' => null,
            'created_at' => $t->created_at?->toIso8601String(),
            'updated_at' => $t->updated_at?->toIso8601String(),
            'transaction' => null,
        ];
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
        return $this->invokeProviderCardAction(
            $userId,
            $cardId,
            fn (array $payload) => $this->mastercardApiClient->check3ds($payload),
            'check_3ds'
        );
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

    /**
     * Refreshes card from Pagocards getcarddetails and returns normalized spend controls parsed from the response.
     *
     * @return array{success: bool, message?: string, status?: int, data?: array{spend_controls: list<array<string, mixed>>}}
     */
    public function listSpendControls(int $userId, int $cardId): array
    {
        $card = $this->getCard($userId, $cardId);
        if (! $card) {
            return [
                'success' => false,
                'message' => 'Virtual card not found.',
                'status' => 404,
            ];
        }

        $payload = is_array($card->provider_payload) ? $card->provider_payload : [];

        return [
            'success' => true,
            'message' => 'Spend controls retrieved successfully.',
            'data' => [
                'spend_controls' => $this->extractSpendControlsFromProviderResponse($payload),
            ],
        ];
    }

    /**
     * @param  array{description: string, type: string, period: string, limit: float|int|string}  $input
     */
    public function createSpendControl(int $userId, int $cardId, array $input): array
    {
        $limit = is_numeric($input['limit']) ? 0 + $input['limit'] : 0;

        return $this->invokeProviderCardAction(
            $userId,
            $cardId,
            fn (array $payload) => $this->mastercardApiClient->spendControl(array_merge($payload, [
                'description' => (string) $input['description'],
                'type' => (string) $input['type'],
                'period' => (string) $input['period'],
                'limit' => $limit,
            ])),
            'spend_control_create'
        );
    }

    public function deleteSpendControl(int $userId, int $cardId, string $controlId): array
    {
        return $this->invokeProviderCardAction(
            $userId,
            $cardId,
            fn (array $payload) => $this->mastercardApiClient->deleteSpendControl(array_merge($payload, [
                'controlid' => $controlId,
            ])),
            'spend_control_delete'
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listPendingPagocardsProviderEvents(int $userId): array
    {
        $rows = VirtualCardProviderWebhookEvent::query()
            ->where('user_id', $userId)
            ->where('status', VirtualCardProviderWebhookEvent::STATUS_PENDING)
            ->whereIn('event_name', [
                PagocardsVirtualCardWebhookService::EVENT_3DS_CREATED,
                PagocardsVirtualCardWebhookService::EVENT_TOKENIZATION,
            ])
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return $rows->map(static function (VirtualCardProviderWebhookEvent $e): array {
            return [
                'id' => $e->id,
                'external_event_id' => $e->external_event_id,
                'event_name' => $e->event_name,
                'event_target_id' => $e->event_target_id,
                'virtual_card_id' => $e->virtual_card_id,
                'status' => $e->status,
                'payload' => $e->payload,
                'created_at' => $e->created_at?->toIso8601String(),
            ];
        })->values()->all();
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function dismissPagocardsProviderEvent(int $userId, int $providerEventId): array
    {
        $row = VirtualCardProviderWebhookEvent::query()
            ->where('id', $providerEventId)
            ->where('user_id', $userId)
            ->where('status', VirtualCardProviderWebhookEvent::STATUS_PENDING)
            ->first();

        if (! $row) {
            return ['success' => false, 'message' => 'Pending event not found.'];
        }

        $row->update([
            'status' => VirtualCardProviderWebhookEvent::STATUS_DISMISSED,
            'processed_at' => now(),
        ]);

        return ['success' => true, 'message' => 'Event dismissed.'];
    }

    public function markPagocardsWebhook3dsEventCompleted(int $userId, int $virtualCardId, string $eventTargetId): void
    {
        if ($eventTargetId === '') {
            return;
        }

        VirtualCardProviderWebhookEvent::query()
            ->where('user_id', $userId)
            ->where('virtual_card_id', $virtualCardId)
            ->where('event_target_id', $eventTargetId)
            ->where('event_name', PagocardsVirtualCardWebhookService::EVENT_3DS_CREATED)
            ->where('status', VirtualCardProviderWebhookEvent::STATUS_PENDING)
            ->update([
                'status' => VirtualCardProviderWebhookEvent::STATUS_COMPLETED,
                'processed_at' => now(),
            ]);
    }

    public function markPagocardsWebhookWalletEventCompleted(int $userId, int $providerEventId): void
    {
        VirtualCardProviderWebhookEvent::query()
            ->where('id', $providerEventId)
            ->where('user_id', $userId)
            ->where('event_name', PagocardsVirtualCardWebhookService::EVENT_TOKENIZATION)
            ->where('status', VirtualCardProviderWebhookEvent::STATUS_PENDING)
            ->update([
                'status' => VirtualCardProviderWebhookEvent::STATUS_COMPLETED,
                'processed_at' => now(),
            ]);
    }

    protected function invokeProviderCardAction(
        int $userId,
        int $cardId,
        callable $apiAction,
        string $actionKey = 'provider_card_action'
    ): array
    {
        $card = VirtualCard::where('id', $cardId)->where('user_id', $userId)->firstOrFail();
        if (! $card->provider_card_id) {
            ApplicationLog::warning('virtual_card', "virtual_card.{$actionKey}.missing_provider_metadata", [
                'user_id' => $userId,
                'card_id' => $cardId,
            ]);
            return ['success' => false, 'message' => 'This card is missing provider metadata.'];
        }

        $user = User::findOrFail($userId);
        $payload = ['email' => $this->resolveProviderAccountEmail($user, $card), 'cardid' => $card->provider_card_id];
        ApplicationLog::info('virtual_card', "virtual_card.{$actionKey}.request_sent", [
            'user_id' => $userId,
            'card_id' => $cardId,
            'provider_card_id' => $card->provider_card_id,
            'payload' => $payload,
        ]);
        try {
            $response = $apiAction($payload);
        } catch (MastercardApiException $exception) {
            ApplicationLog::warning('virtual_card', "virtual_card.{$actionKey}.provider_exception", [
                'user_id' => $userId,
                'card_id' => $cardId,
                'provider_card_id' => $card->provider_card_id,
                'payload' => $payload,
                'error' => $exception->getMessage(),
            ]);
            return ['success' => false, 'message' => $exception->getMessage()];
        }

        $providerFailed = $this->providerResponseIndicatesFailure($response);
        ApplicationLog::info('virtual_card', "virtual_card.{$actionKey}.provider_response", [
            'user_id' => $userId,
            'card_id' => $cardId,
            'provider_card_id' => $card->provider_card_id,
            'payload' => $payload,
            'provider_failed' => $providerFailed,
            'response' => $response,
        ]);

        $card->update([
            'provider_status' => $this->extractStatus($response, $card->provider_status),
            'provider_payload' => $response,
        ]);

        if ($providerFailed) {
            return [
                'success' => false,
                'message' => $this->extractProviderResponseMessage($response, 'Provider action failed.'),
                'data' => [
                    'card' => $card->fresh(),
                    'provider_response' => $response,
                ],
            ];
        }

        return [
            'success' => true,
            'message' => $response['message'] ?? 'Provider action completed successfully',
            'data' => [
                'card' => $card->fresh(),
                'provider_response' => $response,
            ],
        ];
    }

    protected function providerResponseIndicatesFailure(array $response): bool
    {
        $rawSuccess = data_get($response, 'success');
        if (is_bool($rawSuccess)) {
            return $rawSuccess === false;
        }

        $status = strtolower((string) data_get($response, 'status', ''));
        if ($status !== '') {
            return in_array($status, ['failed', 'failure', 'error', 'declined', 'rejected'], true);
        }

        $code = strtolower((string) data_get($response, 'code', ''));
        if ($code !== '') {
            return in_array($code, ['failed', 'failure', 'error', 'declined', 'rejected'], true);
        }

        return false;
    }

    protected function extractProviderResponseMessage(array $response, string $fallback): string
    {
        $candidates = [
            data_get($response, 'message'),
            data_get($response, 'error.message'),
            data_get($response, 'error'),
            data_get($response, 'data.message'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return $fallback;
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
            data_get($response, 'data.total_balance'),
            data_get($response, 'data.totalBalance'),
            data_get($response, 'data.wallet_balance'),
            data_get($response, 'data.walletBalance'),
            data_get($response, 'data.current_balance'),
            data_get($response, 'data.currentBalance'),
            data_get($response, 'data.balance'),
            data_get($response, 'data.available_balance'),
            data_get($response, 'data.availableBalance'),
            data_get($response, 'data.card_balance'),
            data_get($response, 'data.cardBalance'),
            data_get($response, 'balance'),
            data_get($response, 'available_balance'),
            data_get($response, 'availableBalance'),
            data_get($response, 'card_balance'),
            data_get($response, 'cardBalance'),
            // Often the funded amount on fund responses, not remaining balance — keep near fallback
            data_get($response, 'data.amount'),
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

    /**
     * @return list<array<string, mixed>>
     */
    protected function extractSpendControlsFromProviderResponse(array $response): array
    {
        foreach ($this->spendControlListRoots($response) as $maybe) {
            if (! is_array($maybe)) {
                continue;
            }
            $isAssoc = array_keys($maybe) !== range(0, count($maybe) - 1);
            if ($isAssoc) {
                if ($this->looksLikeSpendControlRow($maybe)) {
                    return [$this->normalizeSpendControlRow($maybe)];
                }

                continue;
            }
            $rows = [];
            foreach ($maybe as $row) {
                if (is_array($row) && $this->looksLikeSpendControlRow($row)) {
                    $rows[] = $this->normalizeSpendControlRow($row);
                }
            }
            if ($rows !== []) {
                return $rows;
            }
        }

        return [];
    }

    /**
     * @return list<mixed>
     */
    protected function spendControlListRoots(array $response): array
    {
        $keys = [
            'spendControls', 'spendcontrols', 'spend_controls', 'controls', 'controlList', 'controllist',
            'spendingControls', 'spending_controls',
        ];
        $out = [];
        $data = data_get($response, 'data');
        if (is_array($data)) {
            foreach ($keys as $key) {
                if (array_key_exists($key, $data)) {
                    $out[] = $data[$key];
                }
            }
            $card = $data['card'] ?? null;
            if (is_array($card)) {
                foreach ($keys as $key) {
                    if (array_key_exists($key, $card)) {
                        $out[] = $card[$key];
                    }
                }
            }
        }
        foreach ($keys as $key) {
            if (array_key_exists($key, $response)) {
                $out[] = $response[$key];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function looksLikeSpendControlRow(array $row): bool
    {
        if ($row === []) {
            return false;
        }
        foreach (['controlid', 'controlId', 'control_id', 'id'] as $h) {
            if (! empty($row[$h]) && is_scalar($row[$h])) {
                return true;
            }
        }
        if (array_key_exists('limit', $row) || array_key_exists('period', $row) || array_key_exists('type', $row)) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function normalizeSpendControlRow(array $row): array
    {
        $cid = $row['controlid'] ?? $row['controlId'] ?? $row['control_id'] ?? $row['id'] ?? '';

        return [
            'control_id' => is_scalar($cid) ? (string) $cid : '',
            'description' => (string) ($row['description'] ?? ''),
            'type' => (string) ($row['type'] ?? ''),
            'period' => (string) ($row['period'] ?? ''),
            'limit' => $row['limit'] ?? null,
        ];
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
     * @return array<int, array<string, mixed>>
     */
    protected function extractProviderTransactionsFromResponse(array $response): array
    {
        $candidates = [
            data_get($response, 'data.transactions'),
            data_get($response, 'data.transaction'),
            data_get($response, 'data.card_transactions'),
            data_get($response, 'data.cardTransactions'),
            data_get($response, 'data.card.transaction'),
            data_get($response, 'data.card.transactions'),
            data_get($response, 'data.transaction_history'),
            data_get($response, 'data.transactionHistory'),
            data_get($response, 'data.recent_transactions'),
            data_get($response, 'data.recentTransactions'),
            data_get($response, 'data.activity'),
            data_get($response, 'data.history'),
            data_get($response, 'data.statement'),
            data_get($response, 'data.items'),
            data_get($response, 'transactions'),
            data_get($response, 'transaction'),
            data_get($response, 'items'),
        ];

        foreach ($candidates as $candidate) {
            if (! is_array($candidate) || $candidate === []) {
                continue;
            }

            $isAssoc = array_keys($candidate) !== range(0, count($candidate) - 1);
            if ($isAssoc) {
                if ($this->isLikelyTransactionObject($candidate)) {
                    return [$candidate];
                }

                continue;
            }

            return array_values(array_filter($candidate, 'is_array'));
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $tx
     */
    protected function isLikelyTransactionObject(array $tx): bool
    {
        $keys = [
            'id',
            'transaction_id',
            'reference',
            'txid',
            'amount',
            'transaction_amount',
            'debit_amount',
            'status',
            'transaction_status',
        ];

        foreach ($keys as $key) {
            if (array_key_exists($key, $tx)) {
                return true;
            }
        }

        return false;
    }

    protected function syncProviderTransactions(int $userId, int $cardId, array $providerResponse): void
    {
        $providerTransactions = $this->extractProviderTransactionsFromResponse($providerResponse);
        foreach ($providerTransactions as $providerTransaction) {
            if (! is_array($providerTransaction)) {
                continue;
            }

            $normalized = $this->normalizeProviderTransaction($providerTransaction);
            VirtualCardTransaction::updateOrCreate(
                [
                    'virtual_card_id' => $cardId,
                    'provider_transaction_id' => $normalized['provider_transaction_id'],
                ],
                [
                    'user_id' => $userId,
                    'type' => $normalized['type'],
                    'status' => $normalized['status'],
                    'currency' => $normalized['currency'],
                    'amount' => $normalized['amount'],
                    'fee' => $normalized['fee'],
                    'total_amount' => $normalized['total_amount'],
                    'reference' => $normalized['reference'],
                    'description' => $normalized['description'],
                    'metadata' => $normalized['metadata'],
                    'provider_payload' => $providerTransaction,
                ]
            );
        }
    }

    /**
     * @param  array<string, mixed>  $providerTransaction
     * @return array<string, mixed>
     */
    protected function normalizeProviderTransaction(array $providerTransaction): array
    {
        $providerTxId = (string) ($providerTransaction['id']
            ?? $providerTransaction['transaction_id']
            ?? $providerTransaction['reference']
            ?? $providerTransaction['txid']
            ?? '');
        if ($providerTxId === '') {
            $providerTxId = 'provider_'.md5(json_encode($providerTransaction) ?: uniqid('tx', true));
        }

        $amount = $this->parseMoney(
            $providerTransaction['amount']
            ?? $providerTransaction['transaction_amount']
            ?? $providerTransaction['value']
            ?? $providerTransaction['debit_amount']
            ?? $providerTransaction['debitAmount']
            ?? 0
        );
        $fee = $this->parseMoney($providerTransaction['fee'] ?? $providerTransaction['transaction_fee'] ?? 0);
        $total = $this->parseMoney(
            $providerTransaction['total_amount']
            ?? $providerTransaction['total']
            ?? ($amount + $fee)
        );

        $merchant = is_array($providerTransaction['merchant'] ?? null) ? $providerTransaction['merchant'] : [];
        $merchantName = trim((string) ($merchant['name'] ?? ''));
        $merchantCity = trim((string) ($merchant['city'] ?? ''));
        $merchantCountry = trim((string) ($merchant['country'] ?? ''));
        $merchantMcc = trim((string) ($merchant['mcc'] ?? ''));
        $merchantMid = trim((string) ($merchant['mid'] ?? ''));

        $merchantAmountRaw = $providerTransaction['merchantAmount'] ?? $providerTransaction['merchant_amount'] ?? null;
        $merchantCurrency = (string) ($providerTransaction['merchantCurrency'] ?? $providerTransaction['merchant_currency'] ?? '');
        $paymentAt = $providerTransaction['paymentDateTime']
            ?? $providerTransaction['payment_datetime']
            ?? $providerTransaction['createdAt']
            ?? $providerTransaction['created_at']
            ?? null;
        $declineReason = trim((string) ($providerTransaction['declineReason'] ?? $providerTransaction['decline_reason'] ?? ''));

        $descriptionParts = [];
        if ($merchantName !== '') {
            $descriptionParts[] = $merchantName;
        }
        $loc = trim(implode(', ', array_filter([$merchantCity, $merchantCountry], static fn ($s) => $s !== '')));
        if ($loc !== '') {
            $descriptionParts[] = $loc;
        }
        $description = $descriptionParts !== []
            ? implode(' · ', $descriptionParts)
            : (string) ($providerTransaction['description'] ?? $providerTransaction['narration'] ?? 'Card payment');
        if ($declineReason !== '') {
            $description .= ' ('.$declineReason.')';
        }

        $rawStatus = strtolower((string) ($providerTransaction['status'] ?? $providerTransaction['transaction_status'] ?? ''));
        $status = match ($rawStatus) {
            'complete', 'completed', 'success', 'successful' => 'completed',
            'rejected', 'declined', 'failed', 'cancelled' => 'failed',
            'pending', 'processing' => 'pending',
            default => $rawStatus !== '' ? $rawStatus : 'pending',
        };

        $metadata = array_filter([
            'source' => 'pagocards_card_details',
            'provider_created_at' => $providerTransaction['created_at']
                ?? $providerTransaction['createdAt']
                ?? $providerTransaction['date']
                ?? $providerTransaction['timestamp']
                ?? null,
            'payment_datetime' => $paymentAt,
            'decline_reason' => $declineReason !== '' ? $declineReason : null,
            'merchant_name' => $merchantName !== '' ? $merchantName : null,
            'merchant_city' => $merchantCity !== '' ? $merchantCity : null,
            'merchant_country' => $merchantCountry !== '' ? $merchantCountry : null,
            'merchant_mcc' => $merchantMcc !== '' ? $merchantMcc : null,
            'merchant_mid' => $merchantMid !== '' ? $merchantMid : null,
            'merchant_amount' => $merchantAmountRaw !== null && $merchantAmountRaw !== '' ? $merchantAmountRaw : null,
            'merchant_currency' => $merchantCurrency !== '' ? $merchantCurrency : null,
            'merchant' => $merchant !== [] ? $merchant : null,
        ], static fn ($v) => $v !== null && $v !== '');

        return [
            'provider_transaction_id' => $providerTxId,
            'type' => (string) ($providerTransaction['type'] ?? $providerTransaction['transaction_type'] ?? 'provider'),
            'status' => $status,
            'currency' => (string) ($providerTransaction['currency'] ?? $providerTransaction['curr'] ?? 'USD'),
            'amount' => $amount,
            'fee' => $fee,
            'total_amount' => $total,
            'reference' => (string) ($providerTransaction['reference'] ?? $providerTransaction['rrn'] ?? $providerTxId),
            'description' => $description,
            'metadata' => $metadata,
        ];
    }

    protected function parseMoney(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value)) {
            $clean = preg_replace('/[^\d\.\-]/', '', $value);
            if ($clean === null || $clean === '') {
                return 0.0;
            }

            return (float) $clean;
        }

        if (is_array($value)) {
            $sum = 0.0;
            foreach ($value as $nested) {
                if (is_numeric($nested)) {
                    $sum += (float) $nested;
                } elseif (is_array($nested)) {
                    $sum += $this->parseMoney($nested);
                }
            }

            return $sum;
        }

        return 0.0;
    }

    /**
     * Resolve provider email for an existing card action.
     *
     * Preference order:
     * 1) explicit request overrides
     * 2) stored card metadata provider_account_email
     * 3) authenticated user email
     *
     * @param  array<string, mixed>  $requestData
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
