<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;

class ChatMessage extends Model
{
    protected $appends = ['attachment'];

    protected $hidden = ['attachment_path', 'attachment_type'];

    protected $fillable = [
        'chat_session_id',
        'user_id',
        'admin_id',
        'message',
        'sender_type',
        'status',
        'attachment_path',
        'attachment_type',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    /**
     * Time-limited URL for the stored attachment (mobile Image uses plain GET; signed route avoids /storage 403s).
     */
    public function getAttachmentAttribute(): ?string
    {
        if (! $this->attachment_path || ! $this->id) {
            return null;
        }

        return URL::temporarySignedRoute(
            'chat.message.attachment',
            now()->addDays(30),
            ['message' => $this->id]
        );
    }

    /**
     * Get the chat session that owns the message.
     */
    public function chatSession(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class);
    }

    /**
     * Get the user that sent the message (if sender is user).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the admin that sent the message (if sender is admin).
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}
