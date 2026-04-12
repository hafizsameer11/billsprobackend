<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReceivedAsset extends Model
{
    protected $fillable = [
        'user_id',
        'virtual_account_id',
        'transaction_id',
        'crypto_deposit_address_id',
        'blockchain',
        'currency',
        'amount',
        'tx_hash',
        'log_index',
        'from_address',
        'to_address',
        'source',
        'status',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:8',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function virtualAccount(): BelongsTo
    {
        return $this->belongsTo(VirtualAccount::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function cryptoDepositAddress(): BelongsTo
    {
        return $this->belongsTo(CryptoDepositAddress::class);
    }
}
