<?php

namespace App\Services\VirtualCard;

use App\Models\Transaction;
use App\Models\VirtualCard;
use App\Models\VirtualCardTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class VirtualCardService
{
    public function __construct(protected BsiCardsClient $bsiCardsClient) {}

    /**
     * Create provider-backed merchant digital master card
     */
    public function createCard(int $userId, array $data): array
    {
        $user = User::findOrFail($userId);
        $payload = [
            'useremail' => $data['useremail'] ?? $user->email,
            'firstname' => $data['firstname'] ?? $user->first_name ?? explode(' ', (string) $user->name)[0] ?? 'User',
            'lastname' => $data['lastname'] ?? $user->last_name ?? trim(str_replace(($user->first_name ?? ''), '', (string) $user->name)) ?: 'Cardholder',
            'dob' => $data['dob'],
            'address1' => $data['address1'],
            'postalcode' => $data['postalcode'],
            'city' => $data['city'],
            'country' => $data['country'],
            'state' => $data['state'],
            'countrycode' => $data['countrycode'],
            'phone' => $data['phone'],
        ];

        try {
            $response = $this->bsiCardsClient->createMerchantMasterCard($payload);
        } catch (BsiCardsApiException $exception) {
            return [
                'success' => false,
                'message' => $exception->getMessage(),
                'status' => $exception->getHttpStatus(),
            ];
        }

        return DB::transaction(function () use ($userId, $response) {
            $providerCardId = $this->extractProviderCardId($response) ?? ('prov_' . strtolower(bin2hex(random_bytes(8))));
            $cardSnapshot = $this->extractCardSnapshot($response, $providerCardId);

            $card = VirtualCard::updateOrCreate(
                ['provider_card_id' => $providerCardId, 'user_id' => $userId],
                [
                    'card_name' => $cardSnapshot['card_name'],
                    'card_number' => $cardSnapshot['card_number'],
                    'cvv' => $cardSnapshot['cvv'],
                    'expiry_month' => $cardSnapshot['expiry_month'],
                    'expiry_year' => $cardSnapshot['expiry_year'],
                    'card_type' => 'mastercard',
                    'provider' => 'bsicards',
                    'provider_status' => $this->extractStatus($response),
                    'card_color' => 'green',
                    'currency' => 'USD',
                    'balance' => $this->extractBalance($response),
                    'is_active' => true,
                    'is_frozen' => false,
                    'metadata' => [
                        'source' => 'provider',
                    ],
                    'provider_payload' => $response,
                ]
            );

            $transaction = Transaction::create([
                'user_id' => $userId,
                'transaction_id' => Transaction::generateTransactionId(),
                'type' => 'card_creation',
                'category' => 'virtual_card',
                'status' => 'completed',
                'currency' => 'USD',
                'amount' => 0,
                'fee' => 0,
                'total_amount' => 0,
                'reference' => 'CARD' . strtoupper(substr(md5(uniqid((string) $userId, true)), 0, 12)),
                'description' => 'Merchant digital master card created via provider',
                'metadata' => [
                    'card_id' => $card->id,
                    'provider_card_id' => $providerCardId,
                ],
            ]);

            return [
                'success' => true,
                'message' => $response['message'] ?? 'Virtual card created successfully',
                'data' => [
                    'card' => $card->fresh(),
                    'provider_response' => $response,
                    'transaction' => $transaction,
                ],
            ];
        });
    }

    /**
     * Fund provider-backed merchant digital master card.
     */
    public function fundCard(int $userId, int $cardId, array $data): array
    {
        $card = VirtualCard::where('id', $cardId)
            ->where('user_id', $userId)
            ->firstOrFail();

        if (!$card->provider_card_id) {
            return [
                'success' => false,
                'message' => 'This card is missing provider metadata and cannot be funded.',
            ];
        }

        $user = User::findOrFail($userId);
        $payload = [
            'useremail' => $data['useremail'] ?? $user->email,
            'cardid' => $card->provider_card_id,
            'amount' => (float) $data['amount'],
        ];

        try {
            $response = $this->bsiCardsClient->fundMerchantMasterCard($payload);
        } catch (BsiCardsApiException $exception) {
            $context = $exception->getContext() ?? [];
            if (($context['response'] ?? null) === [] || $exception->getHttpStatus() === 404) {
                $message = 'Provider funding endpoint is not available. Check BSICARDS_MERCHANT_MASTER_FUND_PATH and reseller API contract.';
            } else {
                $message = $exception->getMessage();
            }
            return [
                'success' => false,
                'message' => $message,
                'status' => $exception->getHttpStatus(),
            ];
        }

        return DB::transaction(function () use ($userId, $card, $data, $payload, $response) {
            $freshCard = VirtualCard::where('id', $card->id)
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->firstOrFail();

            $freshCard->update([
                'provider_status' => $this->extractStatus($response, $freshCard->provider_status),
                'provider_payload' => $response,
                'balance' => $this->extractBalance($response, (float) $freshCard->balance + (float) $data['amount']),
            ]);

            $transaction = Transaction::create([
                'user_id' => $userId,
                'transaction_id' => Transaction::generateTransactionId(),
                'type' => 'card_funding',
                'category' => 'virtual_card',
                'status' => 'completed',
                'currency' => 'USD',
                'amount' => (float) $data['amount'],
                'fee' => 0,
                'total_amount' => (float) $data['amount'],
                'reference' => 'FUND' . strtoupper(substr(md5(uniqid((string) $userId, true)), 0, 12)),
                'description' => "Fund merchant digital master card with {$data['amount']} USD",
                'metadata' => [
                    'card_id' => $freshCard->id,
                    'provider_card_id' => $freshCard->provider_card_id,
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
                'amount' => (float) $data['amount'],
                'fee' => 0,
                'total_amount' => (float) $data['amount'],
                'payment_wallet_type' => $data['payment_wallet_type'] ?? 'provider_balance',
                'payment_wallet_currency' => 'USD',
                'exchange_rate' => 1,
                'reference' => $transaction->reference,
                'description' => 'Provider funding operation',
                'provider_payload' => $response,
            ]);

            return [
                'success' => true,
                'message' => $response['message'] ?? 'Card funded successfully',
                'data' => [
                    'card' => $freshCard->fresh(),
                    'transaction' => $transaction,
                    'amount_funded_usd' => (float) $data['amount'],
                    'provider_response' => $response,
                    'funding_payload' => $payload,
                ],
            ];
        });
    }

    /**
     * Merchant master withdrawal route currently unsupported by provider docs.
     */
    public function withdrawFromCard(int $userId, int $cardId, array $data): array
    {
        return [
            'success' => false,
            'message' => 'Card withdrawal is not supported for merchant digital master flow.',
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

        if (!$card->provider_card_id) {
            return [
                'success' => false,
                'message' => 'This card is missing provider metadata.',
            ];
        }

        $user = User::findOrFail($userId);
        $payload = [
            'useremail' => $user->email,
            'cardid' => $card->provider_card_id,
        ];

        try {
            $response = $freeze
                ? $this->bsiCardsClient->blockMerchantMasterCard($payload)
                : $this->bsiCardsClient->unblockMerchantMasterCard($payload);
        } catch (BsiCardsApiException $exception) {
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
            $response = $this->bsiCardsClient->getMerchantMasterCards(['useremail' => $user->email]);
            $cards = $this->extractCardsFromListResponse($response);

            foreach ($cards as $providerCard) {
                $providerCardId = (string) ($providerCard['cardid'] ?? $providerCard['id'] ?? '');
                if ($providerCardId !== '') {
                    $snapshot = $this->extractCardSnapshot(['data' => $providerCard], $providerCardId);
                    VirtualCard::updateOrCreate(
                        ['provider_card_id' => $providerCardId, 'user_id' => $userId],
                        [
                            'card_name' => $snapshot['card_name'],
                            'card_number' => $snapshot['card_number'],
                            'cvv' => $snapshot['cvv'],
                            'expiry_month' => $snapshot['expiry_month'],
                            'expiry_year' => $snapshot['expiry_year'],
                            'provider' => 'bsicards',
                            'provider_status' => (string) ($providerCard['status'] ?? 'active'),
                            'currency' => 'USD',
                            'balance' => (float) ($providerCard['balance'] ?? 0),
                            'provider_payload' => $providerCard,
                        ]
                    );
                }
            }
        } catch (BsiCardsApiException) {
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

        if (!$card || !$card->provider_card_id) {
            return $card;
        }

        $user = User::findOrFail($userId);
        try {
            $response = $this->bsiCardsClient->getMerchantMasterCard([
                'useremail' => $user->email,
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
        } catch (BsiCardsApiException) {
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
            try {
                $providerResponse = $this->bsiCardsClient->merchantMasterTransactions([
                    'useremail' => $user->email,
                    'cardid' => $card->provider_card_id,
                ]);
                $providerTransactions = $providerResponse['data'] ?? [];
                if (is_array($providerTransactions)) {
                    foreach ($providerTransactions as $providerTransaction) {
                        if (!is_array($providerTransaction)) {
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
            } catch (BsiCardsApiException) {
                // return local cache
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
        if (!$card->provider_card_id) {
            return [
                'success' => false,
                'message' => 'This card is missing provider metadata.',
            ];
        }

        $user = User::findOrFail($userId);
        try {
            $response = $this->bsiCardsClient->terminateMerchantMasterCard([
                'useremail' => $user->email,
                'cardid' => $card->provider_card_id,
            ]);
        } catch (BsiCardsApiException $exception) {
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
        return $this->invokeProviderCardAction($userId, $cardId, fn (array $payload) => $this->bsiCardsClient->check3ds($payload));
    }

    public function checkWalletOtp(int $userId, int $cardId): array
    {
        return $this->invokeProviderCardAction($userId, $cardId, fn (array $payload) => $this->bsiCardsClient->checkWallet($payload));
    }

    public function approve3ds(int $userId, int $cardId, string $eventId): array
    {
        return $this->invokeProviderCardAction(
            $userId,
            $cardId,
            fn (array $payload) => $this->bsiCardsClient->approve3ds(array_merge($payload, ['eventId' => $eventId]))
        );
    }

    protected function invokeProviderCardAction(int $userId, int $cardId, callable $apiAction): array
    {
        $card = VirtualCard::where('id', $cardId)->where('user_id', $userId)->firstOrFail();
        if (!$card->provider_card_id) {
            return ['success' => false, 'message' => 'This card is missing provider metadata.'];
        }

        $user = User::findOrFail($userId);
        $payload = ['useremail' => $user->email, 'cardid' => $card->provider_card_id];
        try {
            $response = $apiAction($payload);
        } catch (BsiCardsApiException $exception) {
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
        $value = data_get($response, 'data.balance', data_get($response, 'balance', $fallback));
        return (float) $value;
    }

    protected function extractStatus(array $response, ?string $fallback = 'active'): string
    {
        return (string) (data_get($response, 'data.status') ?? data_get($response, 'status') ?? $fallback ?? 'active');
    }

    protected function extractCardsFromListResponse(array $response): array
    {
        $cards = data_get($response, 'data');
        if (!is_array($cards)) {
            return [];
        }

        $isAssoc = array_keys($cards) !== range(0, count($cards) - 1);
        if ($isAssoc) {
            return [$cards];
        }

        return array_values(array_filter($cards, 'is_array'));
    }
}
