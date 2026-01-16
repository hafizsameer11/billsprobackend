<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:8',
            'fee' => 'decimal:8',
            'total_amount' => 'decimal:8',
            'metadata' => 'array',
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
