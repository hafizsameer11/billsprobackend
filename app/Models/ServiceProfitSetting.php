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
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'fixed_fee' => 'decimal:8',
            'percentage' => 'decimal:4',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
