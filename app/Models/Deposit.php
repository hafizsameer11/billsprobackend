<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Deposit extends Model
{
    protected $fillable = [
        'user_id',
        'bank_account_id',
        'transaction_id',
        'deposit_reference',
        'currency',
        'amount',
        'fee',
        'total_amount',
        'status',
        'payment_method',
        'metadata',
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
     * Get the user that owns the deposit.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the bank account for the deposit.
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /**
     * Get the transaction for the deposit.
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * Generate unique deposit reference
     */
    public static function generateReference(): string
    {
        do {
            $ref = 'DEP' . date('Ymd') . strtoupper(substr(md5(uniqid(rand(), true)), 0, 8));
        } while (self::where('deposit_reference', $ref)->exists());

        return $ref;
    }
}
