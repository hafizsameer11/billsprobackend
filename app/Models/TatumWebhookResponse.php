<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TatumWebhookResponse extends Model
{
    protected $fillable = [
        'account_id',
        'subscription_type',
        'amount',
        'reference',
        'currency',
        'tx_id',
        'block_height',
        'block_hash',
        'from_address',
        'to_address',
        'transaction_date',
        'index',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:8',
            'transaction_date' => 'datetime',
        ];
    }
}
