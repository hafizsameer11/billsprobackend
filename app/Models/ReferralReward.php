<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReferralReward extends Model
{
    public const TYPE_AUTO = 'auto';

    public const TYPE_MANUAL = 'manual';

    public const STATUS_CREDITED = 'credited';

    public const STATUS_REVERSED = 'reversed';

    public const ROLE_REFERRER = 'referrer';

    public const ROLE_REFEREE = 'referee';

    protected $fillable = [
        'beneficiary_user_id',
        'referrer_id',
        'referee_id',
        'action_key',
        'beneficiary_role',
        'amount_ngn',
        'reward_type',
        'status',
        'source_transaction_id',
        'occurrence',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount_ngn' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(User::class, 'beneficiary_user_id');
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_id');
    }

    public function referee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referee_id');
    }
}
