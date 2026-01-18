<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillPaymentCategory extends Model
{
    protected $table = 'bill_payment_categories';

    protected $fillable = [
        'code',
        'name',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the providers for the category.
     */
    public function providers(): HasMany
    {
        return $this->hasMany(BillPaymentProvider::class, 'category_id');
    }

    /**
     * Get the beneficiaries for the category.
     */
    public function beneficiaries(): HasMany
    {
        return $this->hasMany(Beneficiary::class, 'category_id');
    }
}
