<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MasterWalletTransaction extends Model
{
    protected $fillable = [
        'master_wallet_id',
        'user_id',
        'type',
        'blockchain',
        'currency',
        'from_address',
        'to_address',
        'amount',
        'network_fee',
        'tx_hash',
        'internal_transaction_id',
        'crypto_sweep_order_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:18',
            'network_fee' => 'decimal:18',
            'metadata' => 'array',
        ];
    }

    public function masterWallet(): BelongsTo
    {
        return $this->belongsTo(MasterWallet::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sweepOrder(): BelongsTo
    {
        return $this->belongsTo(CryptoSweepOrder::class, 'crypto_sweep_order_id');
    }

    public function internalTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'internal_transaction_id', 'transaction_id');
    }
}
