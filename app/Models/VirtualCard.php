<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VirtualCard extends Model
{
    protected $fillable = [
        'user_id',
        'card_name',
        'card_number',
        'cvv',
        'expiry_month',
        'expiry_year',
        'card_type',
        'card_color',
        'currency',
        'balance',
        'daily_spending_limit',
        'monthly_spending_limit',
        'daily_transaction_limit',
        'monthly_transaction_limit',
        'is_active',
        'is_frozen',
        'billing_address_street',
        'billing_address_city',
        'billing_address_state',
        'billing_address_country',
        'billing_address_postal_code',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'balance' => 'decimal:8',
            'daily_spending_limit' => 'decimal:8',
            'monthly_spending_limit' => 'decimal:8',
            'is_active' => 'boolean',
            'is_frozen' => 'boolean',
            'metadata' => 'array',
        ];
    }

    /**
     * Get the user that owns the virtual card.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the transactions for the virtual card.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(VirtualCardTransaction::class, 'virtual_card_id');
    }

    /**
     * Generate card number
     */
    public static function generateCardNumber(): string
    {
        do {
            // Generate 16-digit card number (Mastercard starts with 5)
            $number = '5' . str_pad((string) rand(100000000000000, 999999999999999), 15, '0', STR_PAD_LEFT);
        } while (self::where('card_number', $number)->exists());

        return $number;
    }

    /**
     * Generate CVV
     */
    public static function generateCvv(): string
    {
        return str_pad((string) rand(100, 999), 3, '0', STR_PAD_LEFT);
    }

    /**
     * Generate expiry date (2 years from now)
     */
    public static function generateExpiry(): array
    {
        $expiry = now()->addYears(2);
        return [
            'expiry_month' => str_pad((string) $expiry->month, 2, '0', STR_PAD_LEFT),
            'expiry_year' => (string) $expiry->year,
        ];
    }

    /**
     * Get masked card number
     */
    public function getMaskedCardNumber(): string
    {
        return '**** **** **** ' . substr($this->card_number, -4);
    }
}
