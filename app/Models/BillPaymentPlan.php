<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillPaymentPlan extends Model
{
    protected $table = 'bill_payment_plans';

    protected $fillable = [
        'provider_id',
        'code',
        'name',
        'amount',
        'currency',
        'data_amount',
        'validity',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:8',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the provider that owns the plan.
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(BillPaymentProvider::class, 'provider_id');
    }
}
