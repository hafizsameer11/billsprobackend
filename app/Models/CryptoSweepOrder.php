<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CryptoSweepOrder extends Model
{
    protected $fillable = [
        'crypto_vendor_id',
        'virtual_account_id',
        'user_id',
        'admin_user_id',
        'blockchain',
        'currency',
        'amount',
        'from_address',
        'to_address',
        'status',
        'tx_hash',
        'failure_reason',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:8',
            'metadata' => 'array',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(CryptoVendor::class, 'crypto_vendor_id');
    }

    public function virtualAccount(): BelongsTo
    {
        return $this->belongsTo(VirtualAccount::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }
}
