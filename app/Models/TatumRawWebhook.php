<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TatumRawWebhook extends Model
{
    protected $fillable = [
        'channel',
        'raw_data',
        'headers',
        'ip_address',
        'user_agent',
        'tx_id',
        'subscription_type',
        'receiving_address',
        'processed',
        'processed_at',
        'error_message',
        'outcome',
    ];

    protected function casts(): array
    {
        return [
            'processed' => 'boolean',
            'processed_at' => 'datetime',
        ];
    }
}
