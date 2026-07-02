<?php

namespace App\Services\Admin;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class AdminNotificationAudienceService
{
    /**
     * @return Collection<int, User>
     */
    public function resolveUsers(string $audience): Collection
    {
        return $this->audienceQuery($audience)->select('users.id')->get();
    }

    public function countUsersWithPushTokens(string $audience): int
    {
        return $this->usersWithPushTokensQuery($audience)->count('users.id');
    }

    /**
     * @return Builder<User>
     */
    public function usersWithPushTokensQuery(string $audience): Builder
    {
        return $this->audienceQuery($audience)->whereHas('expoPushTokens');
    }

    /**
     * @return Builder<User>
     */
    public function audienceQuery(string $audience): Builder
    {
        return match ($audience) {
            'all' => User::query(),
            'active' => User::query()->where('account_status', 'active'),
            'banned' => User::query()->where('account_status', 'banned'),
            'kyc_pending' => User::query()
                ->whereHas('kyc', fn ($q) => $q->where('status', 'pending')),
            'kyc_verified' => User::query()->where('kyc_completed', true),
            'new_users_30d' => User::query()->where('created_at', '>=', now()->subDays(30)),
            default => User::query(),
        };
    }
}
