<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BillCommissionRate extends Model
{
    protected $fillable = [
        'scene',
        'entity_key',
        'tier_key',
        'commission_pct',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'commission_pct' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }
}
