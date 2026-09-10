<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Models\ReferralEarningsWallet;
use App\Models\ReferralProgramSetting;
use App\Models\ReferralRelationship;
use App\Models\ReferralReward;
use App\Models\User;
use App\Services\Admin\AdminAuditService;
use App\Services\Referral\ReferralRewardService;
use App\Services\Referral\ReferralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AdminReferralController extends Controller
{
    public function __construct(
        protected ReferralService $referralService,
        protected ReferralRewardService $rewardService,
        protected AdminAuditService $audit,
    ) {}

    public function settings(): JsonResponse
    {
        $settings = $this->referralService->getOrCreateSettings();

        return ResponseHelper::success([
            'is_enabled' => (bool) $settings->is_enabled,
            'min_transfer_to_wallet_ngn' => (float) $settings->min_transfer_to_wallet_ngn,
            'max_referrals_per_user' => $settings->max_referrals_per_user,
            'max_rewards_per_referral_ngn' => $settings->max_rewards_per_referral_ngn !== null
                ? (float) $settings->max_rewards_per_referral_ngn
                : null,
            'actions' => $settings->actions ?? ReferralProgramSetting::defaultActions(),
            'action_keys' => ReferralProgramSetting::defaultActionKeys(),
        ], 'Referral settings retrieved.');
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'is_enabled' => 'sometimes|boolean',
            'min_transfer_to_wallet_ngn' => 'sometimes|numeric|min:0',
            'max_referrals_per_user' => 'nullable|integer|min:1',
            'max_rewards_per_referral_ngn' => 'nullable|numeric|min:0',
            'actions' => 'sometimes|array',
        ]);

        $settings = $this->referralService->getOrCreateSettings();

        if (array_key_exists('is_enabled', $validated)) {
            $settings->is_enabled = (bool) $validated['is_enabled'];
        }
        if (array_key_exists('min_transfer_to_wallet_ngn', $validated)) {
            $settings->min_transfer_to_wallet_ngn = round((float) $validated['min_transfer_to_wallet_ngn'], 2);
        }
        if (array_key_exists('max_referrals_per_user', $validated)) {
            $settings->max_referrals_per_user = $validated['max_referrals_per_user'];
        }
        if (array_key_exists('max_rewards_per_referral_ngn', $validated)) {
            $settings->max_rewards_per_referral_ngn = $validated['max_rewards_per_referral_ngn'] !== null
                ? round((float) $validated['max_rewards_per_referral_ngn'], 2)
                : null;
        }
        if (isset($validated['actions']) && is_array($validated['actions'])) {
            $settings->actions = $this->normalizeActions($validated['actions']);
        }

        $settings->save();

        $this->audit->log(
            (int) $request->user()->id,
            'referral.settings.update',
            $settings,
            $validated,
            $request
        );

        return ResponseHelper::success([
            'is_enabled' => (bool) $settings->is_enabled,
            'min_transfer_to_wallet_ngn' => (float) $settings->min_transfer_to_wallet_ngn,
            'max_referrals_per_user' => $settings->max_referrals_per_user,
            'max_rewards_per_referral_ngn' => $settings->max_rewards_per_referral_ngn !== null
                ? (float) $settings->max_rewards_per_referral_ngn
                : null,
            'actions' => $settings->actions,
        ], 'Referral settings updated.');
    }

    public function relationships(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));

        $q = ReferralRelationship::query()
            ->with([
                'referrer:id,first_name,last_name,email,referral_code',
                'referee:id,first_name,last_name,email',
            ])
            ->orderByDesc('id');

        if ($search !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';
            $q->where(function ($w) use ($like) {
                $w->where('referral_code_used', 'like', $like)
                    ->orWhereHas('referrer', function ($r) use ($like) {
                        $r->where('email', 'like', $like)
                            ->orWhere('first_name', 'like', $like)
                            ->orWhere('last_name', 'like', $like)
                            ->orWhere('referral_code', 'like', $like);
                    })
                    ->orWhereHas('referee', function ($r) use ($like) {
                        $r->where('email', 'like', $like)
                            ->orWhere('first_name', 'like', $like)
                            ->orWhere('last_name', 'like', $like);
                    });
            });
        }

        $page = $q->paginate($perPage);

        $items = collect($page->items())->map(function (ReferralRelationship $rel) {
            $rewardsSum = (float) ReferralReward::query()
                ->where('referee_id', $rel->referee_id)
                ->where('status', ReferralReward::STATUS_CREDITED)
                ->sum('amount_ngn');

            return [
                'id' => $rel->id,
                'status' => $rel->status,
                'referral_code_used' => $rel->referral_code_used,
                'created_at' => optional($rel->created_at)?->toIso8601String(),
                'rewards_total_ngn' => $rewardsSum,
                'referrer' => $rel->referrer ? [
                    'id' => $rel->referrer->id,
                    'name' => trim(($rel->referrer->first_name ?? '').' '.($rel->referrer->last_name ?? '')),
                    'email' => $rel->referrer->email,
                    'referral_code' => $rel->referrer->referral_code,
                ] : null,
                'referee' => $rel->referee ? [
                    'id' => $rel->referee->id,
                    'name' => trim(($rel->referee->first_name ?? '').' '.($rel->referee->last_name ?? '')),
                    'email' => $rel->referee->email,
                ] : null,
            ];
        })->values();

        return ResponseHelper::success([
            'items' => $items,
            'pagination' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ], 'Referral relationships retrieved.');
    }

    public function updateUser(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'referral_code' => 'sometimes|nullable|string|max:32|regex:/^[A-Za-z0-9_-]+$/',
            'referred_by_user_id' => 'sometimes|nullable|integer|exists:users,id',
            'relationship_status' => 'sometimes|string|in:active,blocked',
        ]);

        $user = User::query()->find($id);
        if (! $user) {
            return ResponseHelper::notFound('User not found.');
        }

        try {
            DB::transaction(function () use ($user, $validated, $request) {
                if (array_key_exists('referral_code', $validated)) {
                    $code = $validated['referral_code'] !== null && $validated['referral_code'] !== ''
                        ? strtoupper(trim((string) $validated['referral_code']))
                        : null;

                    if ($code !== null) {
                        $taken = User::query()
                            ->whereRaw('UPPER(referral_code) = ?', [$code])
                            ->where('id', '!=', $user->id)
                            ->exists();
                        if ($taken) {
                            throw new \RuntimeException('Referral code already in use.');
                        }
                    }

                    $user->referral_code = $code;
                }

                if (array_key_exists('referred_by_user_id', $validated)) {
                    $referrerId = $validated['referred_by_user_id'];
                    if ($referrerId !== null && (int) $referrerId === (int) $user->id) {
                        throw new \RuntimeException('User cannot refer themselves.');
                    }
                    $user->referred_by_user_id = $referrerId;

                    if ($referrerId !== null) {
                        $referrer = User::query()->findOrFail($referrerId);
                        $code = $referrer->referral_code ?: $this->referralService->ensureReferralCode($referrer);
                        ReferralRelationship::query()->updateOrCreate(
                            ['referee_id' => $user->id],
                            [
                                'referrer_id' => $referrer->id,
                                'referral_code_used' => strtoupper((string) $code),
                                'status' => ReferralRelationship::STATUS_ACTIVE,
                            ]
                        );
                    }
                }

                $user->save();

                if (isset($validated['relationship_status'])) {
                    ReferralRelationship::query()
                        ->where('referee_id', $user->id)
                        ->orWhere('referrer_id', $user->id)
                        ->when(
                            isset($validated['relationship_id']),
                            fn ($q) => $q
                        );

                    // Prefer updating as referee relationship when blocking a referral link
                    ReferralRelationship::query()
                        ->where('referee_id', $user->id)
                        ->update(['status' => $validated['relationship_status']]);
                }

                $this->audit->log(
                    (int) $request->user()->id,
                    'referral.user.update',
                    $user,
                    $validated,
                    $request
                );
            });
        } catch (\RuntimeException $e) {
            return ResponseHelper::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            Log::error('admin.referral.update_user_failed: '.$e->getMessage());

            return ResponseHelper::serverError('Unable to update referral user.');
        }

        $earnings = ReferralEarningsWallet::query()->where('user_id', $user->id)->first();

        return ResponseHelper::success([
            'id' => $user->id,
            'referral_code' => $user->referral_code,
            'referred_by_user_id' => $user->referred_by_user_id,
            'earnings_balance_ngn' => $earnings ? (float) $earnings->balance : 0,
        ], 'Referral user updated.');
    }

    public function updateRelationship(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|string|in:active,blocked',
        ]);

        $rel = ReferralRelationship::query()->find($id);
        if (! $rel) {
            return ResponseHelper::notFound('Relationship not found.');
        }

        $rel->status = $validated['status'];
        $rel->save();

        $this->audit->log(
            (int) $request->user()->id,
            'referral.relationship.update',
            $rel,
            $validated,
            $request
        );

        return ResponseHelper::success([
            'id' => $rel->id,
            'status' => $rel->status,
        ], 'Referral relationship updated.');
    }

    public function manualReward(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'amount_ngn' => 'required|numeric|min:0.01',
            'reason' => 'required|string|max:500',
            'referee_id' => 'nullable|integer|exists:users,id',
        ]);

        $result = $this->rewardService->grantManualReward(
            (int) $request->user()->id,
            (int) $validated['user_id'],
            (float) $validated['amount_ngn'],
            (string) $validated['reason'],
            isset($validated['referee_id']) ? (int) $validated['referee_id'] : null
        );

        if (! $result['success']) {
            return ResponseHelper::error($result['message'], (int) ($result['status'] ?? 400));
        }

        $this->audit->log(
            (int) $request->user()->id,
            'referral.reward.manual',
            User::query()->find($validated['user_id']),
            $validated,
            $request
        );

        return ResponseHelper::success($result['data'] ?? [], $result['message']);
    }

    /**
     * @param  array<string, mixed>  $incoming
     * @return array<string, array<string, mixed>>
     */
    protected function normalizeActions(array $incoming): array
    {
        $defaults = ReferralProgramSetting::defaultActions();
        $out = [];

        foreach ($defaults as $key => $default) {
            $row = $incoming[$key] ?? $default;
            if (! is_array($row)) {
                $row = $default;
            }
            $out[$key] = [
                'enabled' => (bool) ($row['enabled'] ?? false),
                'reward_referrer_fixed_ngn' => round((float) ($row['reward_referrer_fixed_ngn'] ?? 0), 2),
                'reward_referrer_percent' => round((float) ($row['reward_referrer_percent'] ?? 0), 4),
                'reward_referee_fixed_ngn' => round((float) ($row['reward_referee_fixed_ngn'] ?? 0), 2),
                'reward_referee_percent' => round((float) ($row['reward_referee_percent'] ?? 0), 4),
                'max_times_per_referral' => (int) ($row['max_times_per_referral'] ?? ($default['max_times_per_referral'] ?? 1)),
            ];
        }

        return $out;
    }
}
