<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Beneficiary extends Model
{
    protected $fillable = [
        'user_id',
        'category_id',
        'provider_id',
        'name',
        'account_number',
        'account_type',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the user that owns the beneficiary.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the category for the beneficiary.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(BillPaymentCategory::class, 'category_id');
    }

    /**
     * Get the provider for the beneficiary.
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(BillPaymentProvider::class, 'provider_id');
    }
}
