<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillPaymentProvider extends Model
{
    protected $table = 'bill_payment_providers';

    protected $fillable = [
        'category_id',
        'code',
        'name',
        'logo_url',
        'country_code',
        'currency',
        'is_active',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    /**
     * Get the category that owns the provider.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(BillPaymentCategory::class, 'category_id');
    }

    /**
     * Get the plans for the provider.
     */
    public function plans(): HasMany
    {
        return $this->hasMany(BillPaymentPlan::class, 'provider_id');
    }

    /**
     * Get the beneficiaries for the provider.
     */
    public function beneficiaries(): HasMany
    {
        return $this->hasMany(Beneficiary::class, 'provider_id');
    }
}
