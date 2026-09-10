<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReferralTransfer extends Model
{
    protected $fillable = [
        'user_id',
        'amount_ngn',
        'transaction_id',
        'earnings_balance_before',
        'earnings_balance_after',
    ];

    protected function casts(): array
    {
        return [
            'amount_ngn' => 'decimal:2',
            'earnings_balance_before' => 'decimal:2',
            'earnings_balance_after' => 'decimal:2',
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
}
