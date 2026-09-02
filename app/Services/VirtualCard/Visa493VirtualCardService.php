<?php

namespace App\Services\VirtualCard;

use App\Models\VirtualCard;

/**
 * 493 BIN Visa flows (`POST /v1/cards/*`). Separate from legacy {@see VisaVirtualCardService}.
 */
class Visa493VirtualCardService
{
    public function __construct(
        protected VirtualCardService $virtualCards,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getCreationFeeQuote(): array
    {
        return $this->virtualCards->getVisa493CreationFeeQuote();
    }

    /**
     * @return array<string, mixed>
     */
    public function estimateFunding(float $principalUsd, string $paymentWalletType, string $fiatCurrency = 'NGN'): array
    {
        return $this->virtualCards->estimateVisa493CardFunding($principalUsd, $paymentWalletType, $fiatCurrency);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createCard(int $userId, array $data): array
    {
        return $this->virtualCards->createVisa493Card($userId, $data);
    }

    public function getCard(int $userId, int $cardId): ?VirtualCard
    {
        if (! $this->userOwnsVisa493Card($userId, $cardId)) {
            return null;
        }

        return $this->virtualCards->getVisaCardForUser($userId, $cardId);
    }

    public function userOwnsVisa493Card(int $userId, int $cardId): bool
    {
        return $this->virtualCards->userOwnsVisa493Card($userId, $cardId);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function fundCard(int $userId, int $cardId, array $data): array
    {
        return $this->virtualCards->fundCard($userId, $cardId, $data);
    }

    /**
     * @return array<string, mixed>
     */
    public function toggleFreeze(int $userId, int $cardId, bool $freeze = true): array
    {
        return $this->virtualCards->toggleFreeze($userId, $cardId, $freeze);
    }

    /**
     * @return array<string, mixed>
     */
    public function getCardTransactions(int $userId, int $cardId, int $limit = 50): array
    {
        return $this->virtualCards->getCardTransactions($userId, $cardId, $limit);
    }

    public function getUserCards(int $userId): \Illuminate\Database\Eloquent\Collection
    {
        return $this->virtualCards->getUserCards($userId);
    }
}
