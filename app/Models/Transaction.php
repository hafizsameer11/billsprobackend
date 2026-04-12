<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Transaction extends Model
{
    protected $fillable = [
        'user_id',
        'transaction_id',
        'type',
        'category',
        'status',
        'currency',
        'amount',
        'fee',
        'total_amount',
        'reference',
        'description',
        'metadata',
        'bank_name',
        'account_number',
        'account_name',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:8',
            'fee' => 'decimal:8',
            'total_amount' => 'decimal:8',
            'metadata' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Get the user that owns the transaction.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Custody record for on-chain crypto credits (when present).
     */
    public function receivedAsset(): HasOne
    {
        return $this->hasOne(ReceivedAsset::class);
    }

    /**
     * Generate unique transaction ID
     */
    public static function generateTransactionId(): string
    {
        do {
            $id = strtolower(bin2hex(random_bytes(10)));
        } while (self::where('transaction_id', $id)->exists());

        return $id;
    }
}
