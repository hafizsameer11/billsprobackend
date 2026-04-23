<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VirtualCardProviderWebhookEvent extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_DISMISSED = 'dismissed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'external_event_id',
        'event_name',
        'event_target_id',
        'pagocards_card_id',
        'pagocards_user_id',
        'virtual_card_id',
        'user_id',
        'status',
        'payload',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function virtualCard(): BelongsTo
    {
        return $this->belongsTo(VirtualCard::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
