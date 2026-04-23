<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PagocardsRawWebhook extends Model
{
    protected $fillable = [
        'raw_data',
        'headers',
        'ip_address',
        'user_agent',
        'processed',
        'processed_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'headers' => 'array',
            'processed' => 'boolean',
            'processed_at' => 'datetime',
        ];
    }
}
