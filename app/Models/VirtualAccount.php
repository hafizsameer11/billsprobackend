<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VirtualAccount extends Model
{
    protected $fillable = [
        'user_id',
        'currency_id',
        'blockchain',
        'currency',
        'customer_id',
        'account_id',
        'account_code',
        'active',
        'frozen',
        'account_balance',
        'available_balance',
        'xpub',
        'accounting_currency',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'frozen' => 'boolean',
        ];
    }

    /**
     * Get the user that owns the virtual account.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the wallet currency for the virtual account.
     */
    public function walletCurrency(): BelongsTo
    {
        return $this->belongsTo(WalletCurrency::class, 'currency_id');
    }
}
