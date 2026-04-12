<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CryptoVendor extends Model
{
    protected $fillable = [
        'name',
        'code',
        'blockchain',
        'currency',
        'wallet_currency_id',
        'payout_address',
        'contract_address',
        'is_active',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function walletCurrency(): BelongsTo
    {
        return $this->belongsTo(WalletCurrency::class);
    }

    public function sweepOrders(): HasMany
    {
        return $this->hasMany(CryptoSweepOrder::class);
    }
}
