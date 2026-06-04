<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformRate extends Model
{
    protected $fillable = [
        'category',
        'service_key',
        'sub_service_key',
        'crypto_asset',
        'network_key',
        'exchange_rate_ngn_per_usd',
        'fixed_fee_ngn',
        'percentage_fee',
        'min_fee_ngn',
        'fee_usd',
        'provider_cost_ngn',
        'provider_cost_usd',
        'provider_pct',
        'provider_pct_cap_ngn',
        'display_label',
        'is_active',
        'slug',
    ];

    protected function casts(): array
    {
        return [
            'exchange_rate_ngn_per_usd' => 'decimal:8',
            'fixed_fee_ngn' => 'decimal:4',
            'percentage_fee' => 'decimal:4',
            'min_fee_ngn' => 'decimal:4',
            'fee_usd' => 'decimal:4',
            'provider_cost_ngn' => 'decimal:4',
            'provider_cost_usd' => 'decimal:8',
            'provider_pct' => 'decimal:4',
            'provider_pct_cap_ngn' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (PlatformRate $m): void {
            $m->slug = self::composeSlug($m);
        });
    }

    public static function composeSlug(self $m): string
    {
        return implode('|', [
            $m->category,
            $m->service_key,
            $m->sub_service_key ?? '',
            $m->crypto_asset ?? '',
            $m->network_key ?? '',
        ]);
    }
}
