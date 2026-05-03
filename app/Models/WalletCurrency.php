<?php

namespace App\Models;

use App\Services\Tatum\DepositAddressService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
     * Buy/sell USD-per-unit spreads for internal NGN ↔ crypto (see CryptoExchangeRate).
     */
    public function exchangeRate(): HasOne
    {
        return $this->hasOne(CryptoExchangeRate::class);
    }

    /**
     * USD per 1 crypto unit when user buys crypto with NGN (from exchange_rates, else legacy `rate`).
     */
    public function usdPerUnitForBuy(): float
    {
        $this->loadMissing('exchangeRate');
        $b = $this->exchangeRate ? (float) $this->exchangeRate->rate_buy : 0.0;

        return $b > 0 ? $b : (float) ($this->rate ?? 0);
    }

    /**
     * USD per 1 crypto unit when user sells crypto for NGN (from exchange_rates, else legacy `rate`).
     */
    public function usdPerUnitForSell(): float
    {
        $this->loadMissing('exchangeRate');
        $s = $this->exchangeRate ? (float) $this->exchangeRate->rate_sell : 0.0;

        return $s > 0 ? $s : (float) ($this->rate ?? 0);
    }

    /**
     * Mid of buy/sell for portfolio display and deposit USD estimates (else `rate`).
     */
    public function usdPerUnitForDisplay(): float
    {
        $this->loadMissing('exchangeRate');
        $buy = $this->exchangeRate ? (float) $this->exchangeRate->rate_buy : 0.0;
        $sell = $this->exchangeRate ? (float) $this->exchangeRate->rate_sell : 0.0;
        $legacy = (float) ($this->rate ?? 0);
        if ($buy > 0 && $sell > 0) {
            return ($buy + $sell) / 2.0;
        }
        if ($buy > 0) {
            return $buy;
        }
        if ($sell > 0) {
            return $sell;
        }

        return $legacy;
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
        $currencyUpper = strtoupper(trim($currency));
        $q = static::query()->where('is_active', true);

        if ($blockchainInput !== null && trim($blockchainInput) !== '') {
            $b = DepositAddressService::normalizeBlockchain($blockchainInput);
            $chainLower = strtolower($b);
            if (in_array($currencyUpper, ['BNB', 'BSC'], true) && $chainLower === 'bnb') {
                $chainLower = 'bsc';
            }

            if ($currencyUpper === 'USDT') {
                $ledger = match ($chainLower) {
                    'bsc' => 'USDT_BSC',
                    'tron' => 'USDT_TRON',
                    default => 'USDT',
                };

                return $q->where('currency', $ledger)
                    ->whereRaw('LOWER(blockchain) = ?', [$chainLower])
                    ->with('exchangeRate')
                    ->first();
            }

            if ($currencyUpper === 'USDC') {
                $ledger = $chainLower === 'bsc' ? 'USDC_BSC' : 'USDC';

                return $q->where('currency', $ledger)
                    ->whereRaw('LOWER(blockchain) = ?', [$chainLower])
                    ->with('exchangeRate')
                    ->first();
            }

            if ($currencyUpper === 'BNB' && $chainLower === 'bsc') {
                return $q->where('currency', 'BSC')
                    ->whereRaw('LOWER(blockchain) = ?', ['bsc'])
                    ->with('exchangeRate')
                    ->first();
            }

            return $q->where('currency', $currencyUpper)
                ->whereRaw('LOWER(blockchain) = ?', [$chainLower])
                ->with('exchangeRate')
                ->first();
        }

        $rows = $q->where('currency', $currencyUpper)->with('exchangeRate')->get();

        return $rows->count() === 1 ? $rows->first() : null;
    }
}
