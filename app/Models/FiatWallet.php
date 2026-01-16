<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiatWallet extends Model
{
    protected $fillable = [
        'user_id',
        'currency',
        'country_code',
        'balance',
        'locked_balance',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'balance' => 'decimal:8',
            'locked_balance' => 'decimal:8',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the user that owns the fiat wallet.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
