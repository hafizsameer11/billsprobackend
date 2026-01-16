<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WalletCurrency extends Model
{
    protected $fillable = [
        'blockchain',
        'currency',
        'symbol',
        'name',
        'icon',
        'price',
        'naira_price',
        'rate',
        'token_type',
        'contract_address',
        'decimals',
        'is_token',
        'blockchain_name',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:8',
            'naira_price' => 'decimal:8',
            'rate' => 'decimal:8',
            'is_token' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the virtual accounts for the wallet currency.
     */
    public function virtualAccounts(): HasMany
    {
        return $this->hasMany(VirtualAccount::class, 'currency_id');
    }
}
