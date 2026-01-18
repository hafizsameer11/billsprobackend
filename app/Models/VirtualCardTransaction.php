<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VirtualCardTransaction extends Model
{
    protected $fillable = [
        'virtual_card_id',
        'user_id',
        'transaction_id',
        'type',
        'status',
        'currency',
        'amount',
        'fee',
        'total_amount',
        'payment_wallet_type',
        'payment_wallet_currency',
        'exchange_rate',
        'reference',
        'description',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:8',
            'fee' => 'decimal:8',
            'total_amount' => 'decimal:8',
            'exchange_rate' => 'decimal:8',
            'metadata' => 'array',
        ];
    }

    /**
     * Get the virtual card for the transaction.
     */
    public function virtualCard(): BelongsTo
    {
        return $this->belongsTo(VirtualCard::class, 'virtual_card_id');
    }

    /**
     * Get the user that owns the transaction.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the main transaction record.
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
