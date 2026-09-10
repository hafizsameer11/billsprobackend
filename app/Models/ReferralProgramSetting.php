<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReferralProgramSetting extends Model
{
    protected $fillable = [
        'is_enabled',
        'min_transfer_to_wallet_ngn',
        'max_referrals_per_user',
        'max_rewards_per_referral_ngn',
        'actions',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'min_transfer_to_wallet_ngn' => 'decimal:2',
            'max_rewards_per_referral_ngn' => 'decimal:2',
            'actions' => 'array',
        ];
    }

    /**
     * @return list<string>
     */
    public static function defaultActionKeys(): array
    {
        return ['signup_verified', 'kyc_approved', 'first_deposit', 'card_create', 'card_fund'];
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultActions(): array
    {
        $blank = [
            'enabled' => false,
            'reward_referrer_fixed_ngn' => 0,
            'reward_referrer_percent' => 0,
            'reward_referee_fixed_ngn' => 0,
            'reward_referee_percent' => 0,
            'max_times_per_referral' => 1,
        ];

        return [
            'signup_verified' => array_merge($blank, ['enabled' => true]),
            'kyc_approved' => array_merge($blank, ['enabled' => true]),
            'first_deposit' => array_merge($blank, ['enabled' => true]),
            'card_create' => $blank,
            'card_fund' => array_merge($blank, ['max_times_per_referral' => 0]),
        ];
    }
}
