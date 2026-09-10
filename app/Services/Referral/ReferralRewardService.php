<?php

namespace App\Services\Referral;

use App\Helpers\NotificationHelper;
use App\Models\ReferralEarningsWallet;
use App\Models\ReferralProgramSetting;
use App\Models\ReferralRelationship;
use App\Models\ReferralReward;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReferralRewardService
{
    /**
     * Award referral rewards for a qualifying action performed by the referee.
     *
     * @param  float  $basisAmountNgn  Amount used for percentage calculation (0 for fixed-only actions)
     */
    public function awardForAction(
        User $referee,
        string $actionKey,
        float $basisAmountNgn = 0.0,
        ?int $sourceTransactionId = null
    ): void {
        try {
            $settings = ReferralProgramSetting::query()->first();
            if (! $settings || ! $settings->is_enabled) {
                return;
            }

            $actions = is_array($settings->actions) ? $settings->actions : ReferralProgramSetting::defaultActions();
            $action = $actions[$actionKey] ?? null;
            if (! is_array($action) || empty($action['enabled'])) {
                return;
            }

            $relationship = ReferralRelationship::query()
                ->where('referee_id', $referee->id)
                ->where('status', ReferralRelationship::STATUS_ACTIVE)
                ->first();

            if (! $relationship && $referee->referred_by_user_id) {
                // Late attach if relationship missing but referred_by set
                $referrer = User::query()->find($referee->referred_by_user_id);
                if ($referrer && (int) $referrer->id !== (int) $referee->id) {
                    $relationship = ReferralRelationship::query()->firstOrCreate(
                        ['referee_id' => $referee->id],
                        [
                            'referrer_id' => $referrer->id,
                            'referral_code_used' => strtoupper((string) ($referrer->referral_code ?? 'MANUAL')),
                            'status' => ReferralRelationship::STATUS_ACTIVE,
                        ]
                    );
                }
            }

            if (! $relationship || ! $relationship->isActive()) {
                return;
            }

            $maxTimes = (int) ($action['max_times_per_referral'] ?? 1);
            $occurrence = $this->nextOccurrence((int) $referee->id, $actionKey, $maxTimes);
            if ($occurrence === null) {
                return;
            }

            $cap = $settings->max_rewards_per_referral_ngn !== null
                ? (float) $settings->max_rewards_per_referral_ngn
                : null;

            $referrerAmount = $this->computeAmount(
                (float) ($action['reward_referrer_fixed_ngn'] ?? 0),
                (float) ($action['reward_referrer_percent'] ?? 0),
                $basisAmountNgn
            );
            $refereeAmount = $this->computeAmount(
                (float) ($action['reward_referee_fixed_ngn'] ?? 0),
                (float) ($action['reward_referee_percent'] ?? 0),
                $basisAmountNgn
            );

            if ($cap !== null && $cap > 0) {
                $already = (float) ReferralReward::query()
                    ->where('referee_id', $referee->id)
                    ->where('status', ReferralReward::STATUS_CREDITED)
                    ->sum('amount_ngn');
                $remaining = max(0, $cap - $already);
                if ($remaining <= 0) {
                    return;
                }
                $totalWanted = $referrerAmount + $refereeAmount;
                if ($totalWanted > $remaining && $totalWanted > 0) {
                    $scale = $remaining / $totalWanted;
                    $referrerAmount = round($referrerAmount * $scale, 2);
                    $refereeAmount = round($refereeAmount * $scale, 2);
                }
            }

            DB::transaction(function () use (
                $relationship,
                $referee,
                $actionKey,
                $referrerAmount,
                $refereeAmount,
                $basisAmountNgn,
                $sourceTransactionId,
                $occurrence,
                $action
            ) {
                if ($referrerAmount >= 0.01) {
                    $this->creditReward(
                        beneficiaryUserId: (int) $relationship->referrer_id,
                        referrerId: (int) $relationship->referrer_id,
                        refereeId: (int) $referee->id,
                        actionKey: $actionKey,
                        role: ReferralReward::ROLE_REFERRER,
                        amount: $referrerAmount,
                        rewardType: ReferralReward::TYPE_AUTO,
                        sourceTransactionId: $sourceTransactionId,
                        occurrence: $occurrence,
                        metadata: [
                            'basis_amount_ngn' => $basisAmountNgn,
                            'fixed_ngn' => (float) ($action['reward_referrer_fixed_ngn'] ?? 0),
                            'percent' => (float) ($action['reward_referrer_percent'] ?? 0),
                        ]
                    );
                }

                if ($refereeAmount >= 0.01) {
                    $this->creditReward(
                        beneficiaryUserId: (int) $referee->id,
                        referrerId: (int) $relationship->referrer_id,
                        refereeId: (int) $referee->id,
                        actionKey: $actionKey,
                        role: ReferralReward::ROLE_REFEREE,
                        amount: $refereeAmount,
                        rewardType: ReferralReward::TYPE_AUTO,
                        sourceTransactionId: $sourceTransactionId,
                        occurrence: $occurrence,
                        metadata: [
                            'basis_amount_ngn' => $basisAmountNgn,
                            'fixed_ngn' => (float) ($action['reward_referee_fixed_ngn'] ?? 0),
                            'percent' => (float) ($action['reward_referee_percent'] ?? 0),
                        ]
                    );
                }
            });
        } catch (\Throwable $e) {
            Log::warning('referral.award_failed', [
                'referee_id' => $referee->id,
                'action_key' => $actionKey,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{success: bool, message: string, status?: int, data?: array<string, mixed>}
     */
    public function grantManualReward(
        int $adminUserId,
        int $beneficiaryUserId,
        float $amountNgn,
        string $reason,
        ?int $refereeId = null
    ): array {
        $amountNgn = round($amountNgn, 2);
        if ($amountNgn < 0.01) {
            return ['success' => false, 'message' => 'Amount must be at least ₦0.01.', 'status' => 422];
        }

        $beneficiary = User::query()->find($beneficiaryUserId);
        if (! $beneficiary) {
            return ['success' => false, 'message' => 'User not found.', 'status' => 404];
        }

        try {
            $reward = DB::transaction(function () use (
                $beneficiaryUserId,
                $amountNgn,
                $reason,
                $adminUserId,
                $refereeId
            ) {
                return $this->creditReward(
                    beneficiaryUserId: $beneficiaryUserId,
                    referrerId: $beneficiaryUserId,
                    refereeId: $refereeId,
                    actionKey: 'manual',
                    role: ReferralReward::ROLE_REFERRER,
                    amount: $amountNgn,
                    rewardType: ReferralReward::TYPE_MANUAL,
                    sourceTransactionId: null,
                    occurrence: (int) (microtime(true) * 1000) % 1000000000,
                    metadata: [
                        'admin_user_id' => $adminUserId,
                        'reason' => $reason,
                    ]
                );
            });
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'status' => 400];
        }

        return [
            'success' => true,
            'message' => 'Manual referral reward credited.',
            'data' => [
                'reward_id' => $reward->id,
                'amount_ngn' => (float) $reward->amount_ngn,
                'beneficiary_user_id' => $beneficiaryUserId,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    protected function creditReward(
        int $beneficiaryUserId,
        ?int $referrerId,
        ?int $refereeId,
        string $actionKey,
        string $role,
        float $amount,
        string $rewardType,
        ?int $sourceTransactionId,
        int $occurrence,
        array $metadata
    ): ReferralReward {
        $existing = ReferralReward::query()
            ->where('beneficiary_user_id', $beneficiaryUserId)
            ->where('referee_id', $refereeId)
            ->where('action_key', $actionKey)
            ->where('beneficiary_role', $role)
            ->where('occurrence', $occurrence)
            ->first();

        if ($existing) {
            return $existing;
        }

        $wallet = ReferralEarningsWallet::query()
            ->where('user_id', $beneficiaryUserId)
            ->lockForUpdate()
            ->first();

        if (! $wallet) {
            $wallet = ReferralEarningsWallet::query()->create([
                'user_id' => $beneficiaryUserId,
                'balance' => 0,
            ]);
            $wallet = ReferralEarningsWallet::query()->whereKey($wallet->id)->lockForUpdate()->first();
        }

        $before = (float) $wallet->balance;
        $after = round($before + $amount, 2);
        $wallet->balance = (string) $after;
        $wallet->save();

        $reward = ReferralReward::query()->create([
            'beneficiary_user_id' => $beneficiaryUserId,
            'referrer_id' => $referrerId,
            'referee_id' => $refereeId,
            'action_key' => $actionKey,
            'beneficiary_role' => $role,
            'amount_ngn' => $amount,
            'reward_type' => $rewardType,
            'status' => ReferralReward::STATUS_CREDITED,
            'source_transaction_id' => $sourceTransactionId,
            'occurrence' => $occurrence,
            'metadata' => array_merge($metadata, [
                'earnings_balance_before' => $before,
                'earnings_balance_after' => $after,
            ]),
        ]);

        try {
            $user = User::query()->find($beneficiaryUserId);
            if ($user) {
                NotificationHelper::createTransactionNotification(
                    $user,
                    'referral',
                    'Referral reward earned',
                    'You earned ₦'.number_format($amount, 2).' in referral earnings.',
                    [
                        'action' => 'referral_reward',
                        'action_key' => $actionKey,
                        'amount' => $amount,
                    ]
                );
            }
        } catch (\Throwable $e) {
            Log::warning('referral.reward_notification_failed', ['message' => $e->getMessage()]);
        }

        return $reward;
    }

    protected function computeAmount(float $fixed, float $percent, float $basis): float
    {
        $pctPart = $percent > 0 && $basis > 0 ? ($basis * $percent / 100.0) : 0.0;

        return round(max(0, $fixed) + max(0, $pctPart), 2);
    }

    protected function nextOccurrence(int $refereeId, string $actionKey, int $maxTimes): ?int
    {
        // 0 = unlimited
        $count = ReferralReward::query()
            ->where('referee_id', $refereeId)
            ->where('action_key', $actionKey)
            ->where('beneficiary_role', ReferralReward::ROLE_REFERRER)
            ->where('status', ReferralReward::STATUS_CREDITED)
            ->count();

        if ($maxTimes > 0 && $count >= $maxTimes) {
            return null;
        }

        return $count + 1;
    }
}
