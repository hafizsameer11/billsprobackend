<?php

namespace App\Services\Referral;

use App\Helpers\NotificationHelper;
use App\Models\FiatWallet;
use App\Models\ReferralEarningsWallet;
use App\Models\ReferralProgramSetting;
use App\Models\ReferralRelationship;
use App\Models\ReferralReward;
use App\Models\ReferralTransfer;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ReferralService
{
    public function __construct(
        protected ReferralRewardService $rewards,
    ) {}

    public function getOrCreateSettings(): ReferralProgramSetting
    {
        $settings = ReferralProgramSetting::query()->first();
        if ($settings) {
            return $settings;
        }

        return ReferralProgramSetting::query()->create([
            'is_enabled' => false,
            'min_transfer_to_wallet_ngn' => 100,
            'max_referrals_per_user' => null,
            'max_rewards_per_referral_ngn' => null,
            'actions' => ReferralProgramSetting::defaultActions(),
        ]);
    }

    public function ensureReferralCode(User $user): string
    {
        if (is_string($user->referral_code) && $user->referral_code !== '') {
            return strtoupper($user->referral_code);
        }

        $code = $this->generateUniqueCode($user);
        $user->forceFill(['referral_code' => $code])->save();

        return $code;
    }

    public function findReferrerByCode(?string $code): ?User
    {
        $normalized = $this->normalizeCode($code);
        if ($normalized === '') {
            return null;
        }

        return User::query()
            ->whereRaw('UPPER(referral_code) = ?', [$normalized])
            ->first();
    }

    /**
     * Attach referral at register time. Stores referred_by_user_id; relationship is created on email verify.
     *
     * @return array{success: bool, message?: string, referrer_id?: int}
     */
    public function resolveReferrerForRegistration(?string $code): array
    {
        $normalized = $this->normalizeCode($code);
        if ($normalized === '') {
            return ['success' => true];
        }

        $referrer = $this->findReferrerByCode($normalized);
        if (! $referrer) {
            return ['success' => false, 'message' => 'Invalid referral code.'];
        }

        $settings = $this->getOrCreateSettings();
        if ($settings->max_referrals_per_user !== null) {
            $count = ReferralRelationship::query()
                ->where('referrer_id', $referrer->id)
                ->where('status', ReferralRelationship::STATUS_ACTIVE)
                ->count();
            if ($count >= (int) $settings->max_referrals_per_user) {
                return ['success' => false, 'message' => 'This referral code has reached its referral limit.'];
            }
        }

        return ['success' => true, 'referrer_id' => (int) $referrer->id];
    }

    /**
     * Create relationship after email verification and fire signup_verified reward.
     */
    public function finalizeReferralAfterEmailVerify(User $user): void
    {
        try {
            if (! $user->referred_by_user_id) {
                return;
            }

            $referrer = User::query()->find($user->referred_by_user_id);
            if (! $referrer || (int) $referrer->id === (int) $user->id) {
                return;
            }

            $existing = ReferralRelationship::query()->where('referee_id', $user->id)->first();
            if ($existing) {
                $this->rewards->awardForAction($user, 'signup_verified', 0.0, null);

                return;
            }

            $code = $referrer->referral_code ?: $this->ensureReferralCode($referrer);

            ReferralRelationship::query()->create([
                'referrer_id' => $referrer->id,
                'referee_id' => $user->id,
                'referral_code_used' => strtoupper((string) $code),
                'status' => ReferralRelationship::STATUS_ACTIVE,
            ]);

            $this->rewards->awardForAction($user, 'signup_verified', 0.0, null);
        } catch (\Throwable $e) {
            Log::warning('referral.finalize_after_verify_failed', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getOverviewForUser(User $user): array
    {
        $settings = $this->getOrCreateSettings();
        $code = $this->ensureReferralCode($user);
        $wallet = $this->getOrCreateEarningsWallet($user->id);

        $referrals = ReferralRelationship::query()
            ->with(['referee:id,first_name,last_name,email,created_at'])
            ->where('referrer_id', $user->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(function (ReferralRelationship $rel) {
                $referee = $rel->referee;
                $name = trim(($referee->first_name ?? '').' '.($referee->last_name ?? ''));
                if ($name === '') {
                    $name = 'User';
                }
                $email = (string) ($referee->email ?? '');
                $maskedEmail = $email !== '' ? $this->maskEmail($email) : null;

                return [
                    'id' => $rel->id,
                    'status' => $rel->status,
                    'joined_at' => optional($rel->created_at)?->toIso8601String(),
                    'referee_name' => $name,
                    'referee_email_masked' => $maskedEmail,
                ];
            })
            ->values()
            ->all();

        $recentRewards = ReferralReward::query()
            ->where('beneficiary_user_id', $user->id)
            ->where('status', ReferralReward::STATUS_CREDITED)
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->map(fn (ReferralReward $r) => [
                'id' => $r->id,
                'action_key' => $r->action_key,
                'amount_ngn' => (float) $r->amount_ngn,
                'reward_type' => $r->reward_type,
                'beneficiary_role' => $r->beneficiary_role,
                'created_at' => optional($r->created_at)?->toIso8601String(),
            ])
            ->values()
            ->all();

        $totalEarned = (float) ReferralReward::query()
            ->where('beneficiary_user_id', $user->id)
            ->where('status', ReferralReward::STATUS_CREDITED)
            ->sum('amount_ngn');

        $totalTransferred = (float) ReferralTransfer::query()
            ->where('user_id', $user->id)
            ->sum('amount_ngn');

        return [
            'program_enabled' => (bool) $settings->is_enabled,
            'referral_code' => $code,
            'share_message' => 'Join BillsPro with my referral code '.$code.' and start managing bills, wallets, and virtual cards.',
            'earnings_balance_ngn' => (float) $wallet->balance,
            'min_transfer_to_wallet_ngn' => (float) $settings->min_transfer_to_wallet_ngn,
            'stats' => [
                'total_referrals' => ReferralRelationship::query()->where('referrer_id', $user->id)->count(),
                'active_referrals' => ReferralRelationship::query()
                    ->where('referrer_id', $user->id)
                    ->where('status', ReferralRelationship::STATUS_ACTIVE)
                    ->count(),
                'total_earned_ngn' => round($totalEarned, 2),
                'total_transferred_ngn' => round($totalTransferred, 2),
            ],
            'referrals' => $referrals,
            'recent_rewards' => $recentRewards,
            'actions' => $settings->actions ?? ReferralProgramSetting::defaultActions(),
        ];
    }

    /**
     * @return array{success: bool, message: string, status?: int, data?: array<string, mixed>}
     */
    public function transferToMainWallet(User $user, float $amount): array
    {
        $settings = $this->getOrCreateSettings();
        $amount = round($amount, 2);
        if ($amount <= 0) {
            return ['success' => false, 'message' => 'Enter a valid amount.', 'status' => 422];
        }

        $min = (float) $settings->min_transfer_to_wallet_ngn;
        if ($amount + 0.0001 < $min) {
            return [
                'success' => false,
                'message' => 'Minimum transfer to main wallet is ₦'.number_format($min, 2).'.',
                'status' => 422,
            ];
        }

        try {
            $result = DB::transaction(function () use ($user, $amount) {
                $earnings = ReferralEarningsWallet::query()
                    ->where('user_id', $user->id)
                    ->lockForUpdate()
                    ->first();

                if (! $earnings) {
                    $earnings = ReferralEarningsWallet::query()->create([
                        'user_id' => $user->id,
                        'balance' => 0,
                    ]);
                    $earnings = ReferralEarningsWallet::query()->whereKey($earnings->id)->lockForUpdate()->first();
                }

                $before = (float) $earnings->balance;
                if ($amount > $before + 0.0001) {
                    throw new \RuntimeException('Insufficient referral earnings balance.');
                }

                $after = round($before - $amount, 2);
                $earnings->balance = (string) $after;
                $earnings->save();

                $fiat = FiatWallet::query()
                    ->where('user_id', $user->id)
                    ->where('currency', 'NGN')
                    ->where('country_code', 'NG')
                    ->lockForUpdate()
                    ->first();

                if (! $fiat) {
                    $fiat = FiatWallet::query()->create([
                        'user_id' => $user->id,
                        'currency' => 'NGN',
                        'country_code' => 'NG',
                        'balance' => 0,
                        'locked_balance' => 0,
                        'is_active' => true,
                    ]);
                    $fiat = FiatWallet::query()->whereKey($fiat->id)->lockForUpdate()->first();
                }

                $fiat->increment('balance', $amount);

                $tx = Transaction::create([
                    'user_id' => $user->id,
                    'transaction_id' => Transaction::generateTransactionId(),
                    'type' => 'referral_payout',
                    'category' => 'referral',
                    'status' => 'completed',
                    'currency' => 'NGN',
                    'amount' => (string) $amount,
                    'fee' => '0',
                    'total_amount' => (string) $amount,
                    'reference' => 'REF-PAYOUT-'.$user->id.'-'.time(),
                    'description' => 'Referral earnings moved to Naira wallet',
                    'metadata' => [
                        'source' => 'referral_earnings',
                        'earnings_balance_before' => $before,
                        'earnings_balance_after' => $after,
                    ],
                    'completed_at' => now(),
                ]);

                ReferralTransfer::query()->create([
                    'user_id' => $user->id,
                    'amount_ngn' => $amount,
                    'transaction_id' => $tx->id,
                    'earnings_balance_before' => $before,
                    'earnings_balance_after' => $after,
                ]);

                return [
                    'amount_ngn' => $amount,
                    'earnings_balance_ngn' => $after,
                    'transaction_id' => $tx->transaction_id,
                    'naira_wallet_balance' => (float) $fiat->fresh()->balance,
                ];
            });
        } catch (\RuntimeException $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'status' => 400];
        }

        try {
            NotificationHelper::createTransactionNotification(
                $user,
                'referral',
                'Referral earnings transferred',
                '₦'.number_format($amount, 2).' was moved from referral earnings to your Naira wallet.',
                ['action' => 'referral_transfer', 'amount' => $amount]
            );
        } catch (\Throwable $e) {
            Log::warning('referral.transfer_notification_failed', ['message' => $e->getMessage()]);
        }

        return [
            'success' => true,
            'message' => 'Referral earnings transferred to your Naira wallet.',
            'data' => $result,
        ];
    }

    public function getOrCreateEarningsWallet(int $userId): ReferralEarningsWallet
    {
        return ReferralEarningsWallet::query()->firstOrCreate(
            ['user_id' => $userId],
            ['balance' => 0]
        );
    }

    public function normalizeCode(?string $code): string
    {
        return strtoupper(trim((string) $code));
    }

    protected function generateUniqueCode(User $user): string
    {
        $base = preg_replace('/[^A-Z0-9]/', '', strtoupper((string) ($user->first_name ?? 'USER'))) ?: 'USER';
        $base = substr($base, 0, 6);

        for ($i = 0; $i < 20; $i++) {
            $suffix = strtoupper(Str::random(4));
            $code = $base.$suffix;
            if (! User::query()->where('referral_code', $code)->exists()) {
                return $code;
            }
        }

        return 'BP'.strtoupper(Str::random(8));
    }

    protected function maskEmail(string $email): string
    {
        $parts = explode('@', $email, 2);
        if (count($parts) !== 2) {
            return '***';
        }
        $local = $parts[0];
        $domain = $parts[1];
        $visible = substr($local, 0, min(2, strlen($local)));

        return $visible.'***@'.$domain;
    }
}
