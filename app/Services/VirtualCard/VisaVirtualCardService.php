<?php

namespace App\Services\VirtualCard;

use App\Models\VirtualCard;

/**
 * Visa virtual card flows (Pagocards /visacard/*). Fees use platform_rates `visa_*`; implementation lives on {@see VirtualCardService}.
 */
class VisaVirtualCardService
{
    public function __construct(
        protected VirtualCardService $virtualCards,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getCreationFeeQuote(): array
    {
        return $this->virtualCards->getVisaCreationFeeQuote();
    }

    /**
     * @return array<string, mixed>
     */
    public function estimateFunding(float $principalUsd, string $paymentWalletType, string $fiatCurrency = 'NGN'): array
    {
        return $this->virtualCards->estimateVisaCardFunding($principalUsd, $paymentWalletType, $fiatCurrency);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createCard(int $userId, array $data): array
    {
        return $this->virtualCards->createVisaCard($userId, $data);
    }

    public function getCard(int $userId, int $cardId): ?VirtualCard
    {
        return $this->virtualCards->getVisaCardForUser($userId, $cardId);
    }

    public function userOwnsVisaCard(int $userId, int $cardId): bool
    {
        return VirtualCard::query()
            ->where('id', $cardId)
            ->where('user_id', $userId)
            ->whereRaw('LOWER(card_type) = ?', ['visa'])
            ->exists();
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
