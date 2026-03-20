<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PalmPayBillOrder extends Model
{
    protected $fillable = [
        'user_id',
        'transaction_id',
        'fiat_wallet_id',
        'out_order_no',
        'palmpay_order_no',
        'scene_code',
        'biller_id',
        'item_id',
        'recharge_account',
        'amount',
        'currency',
        'status',
        'palmpay_status',
        'refunded',
        'refunded_at',
        'refund_reason',
        'provider_response',
        'error_message',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:8',
            'refunded' => 'boolean',
            'provider_response' => 'array',
            'refunded_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function fiatWallet(): BelongsTo
    {
        return $this->belongsTo(FiatWallet::class, 'fiat_wallet_id');
    }
}
