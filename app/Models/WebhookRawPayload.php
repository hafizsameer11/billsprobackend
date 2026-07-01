<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookRawPayload extends Model
{
    protected $fillable = [
        'provider',
        'channel',
        'payload',
        'headers',
        'ip_address',
        'user_agent',
        'tx_id',
        'subscription_type',
        'receiving_address',
        'tatum_raw_webhook_id',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'headers' => 'array',
        ];
    }

    public function tatumRawWebhook(): BelongsTo
    {
        return $this->belongsTo(TatumRawWebhook::class);
    }
}
