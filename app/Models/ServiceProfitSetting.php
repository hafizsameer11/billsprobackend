<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceProfitSetting extends Model
{
    protected $fillable = [
        'service_key',
        'label',
        'fixed_fee',
        'percentage',
        'percentage_basis',
        'margin_mode',
        'provider_cost_ngn',
        'provider_cost_usd',
        'provider_pct',
        'provider_pct_cap_ngn',
        'linked_rate_slug',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'fixed_fee' => 'decimal:8',
            'percentage' => 'decimal:4',
            'provider_cost_ngn' => 'decimal:8',
            'provider_cost_usd' => 'decimal:8',
            'provider_pct' => 'decimal:4',
            'provider_pct_cap_ngn' => 'decimal:8',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
