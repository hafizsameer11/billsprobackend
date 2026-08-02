<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CardDeclineFeeCharge extends Model
{
    public const FUNDING_MERCHANT = 'merchant';

    public const FUNDING_CARD = 'card';

    public const STATUS_CHARGED = 'charged';

    public const STATUS_RECOVERED = 'recovered';

    public const STATUS_WAIVED = 'waived';

    protected $fillable = [
        'user_id',
        'virtual_card_id',
        'pagocards_admin_tx_id',
        'pagocards_admin_tx_uuid',
        'provider_card_id',
        'declined_reference',
        'provider_cost_usd',
        'billable_usd',
        'platform_rate_id',
        'exchange_rate_ngn_per_usd',
        'amount_ngn',
        'funding_source',
        'detection_method',
        'recovery_status',
        'naira_transaction_id',
        'card_subsidy_sequence',
        'metadata',
        'recovered_at',
    ];

    protected function casts(): array
    {
        return [
            'provider_cost_usd' => 'decimal:4',
            'billable_usd' => 'decimal:4',
            'exchange_rate_ngn_per_usd' => 'decimal:8',
            'amount_ngn' => 'decimal:4',
            'metadata' => 'array',
            'recovered_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function virtualCard(): BelongsTo
    {
        return $this->belongsTo(VirtualCard::class);
    }

    public function platformRate(): BelongsTo
    {
        return $this->belongsTo(PlatformRate::class);
    }

    public function nairaTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'naira_transaction_id');
    }
}
