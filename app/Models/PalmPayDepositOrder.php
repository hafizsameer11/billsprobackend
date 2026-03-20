<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PalmPayDepositOrder extends Model
{
    protected $fillable = [
        'user_id',
        'deposit_id',
        'merchant_order_id',
        'palmpay_order_no',
        'order_status',
        'virtual_account',
        'checkout_url',
        'raw_create_response',
    ];

    protected function casts(): array
    {
        return [
            'virtual_account' => 'array',
            'raw_create_response' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function deposit(): BelongsTo
    {
        return $this->belongsTo(Deposit::class);
    }
}
