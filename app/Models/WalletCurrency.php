<?php

namespace App\Models;

use App\Services\Tatum\DepositAddressService;
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

    /**
     * Resolve an active wallet currency row by symbol and optional chain.
     * When $blockchainInput is null and multiple chains exist for the currency, returns null (caller must pass blockchain).
     */
    public static function findActiveForCrypto(string $currency, ?string $blockchainInput = null): ?self
    {
        $currency = strtoupper(trim($currency));
        $q = static::query()->where('currency', $currency)->where('is_active', true);

        if ($blockchainInput !== null && trim($blockchainInput) !== '') {
            $b = DepositAddressService::normalizeBlockchain($blockchainInput);

            return $q->whereRaw('LOWER(blockchain) = ?', [strtolower($b)])->first();
        }

        $rows = $q->get();

        return $rows->count() === 1 ? $rows->first() : null;
    }
}
