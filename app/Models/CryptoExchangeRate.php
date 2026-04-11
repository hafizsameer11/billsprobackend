<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CryptoExchangeRate extends Model
{
    protected $fillable = [
        'wallet_currency_id',
        'rate_buy',
        'rate_sell',
    ];

    protected function casts(): array
    {
        return [
            'rate_buy' => 'decimal:8',
            'rate_sell' => 'decimal:8',
        ];
    }

    public function walletCurrency(): BelongsTo
    {
        return $this->belongsTo(WalletCurrency::class);
    }
}
