<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommissionVolumeTier extends Model
{
    protected $fillable = [
        'tier_key',
        'label',
        'min_monthly_volume_ngn',
        'max_monthly_volume_ngn',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'min_monthly_volume_ngn' => 'decimal:2',
            'max_monthly_volume_ngn' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }
}
