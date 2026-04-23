<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Monolog\Level;

class ApplicationLog extends Model
{
    protected $table = 'application_logs';

    protected $fillable = [
        'level',
        'level_name',
        'channel',
        'message',
        'context',
        'extra',
        'logged_at',
    ];

    protected function casts(): array
    {
        return [
            'logged_at' => 'datetime',
            'context' => 'array',
            'extra' => 'array',
        ];
    }

    /**
     * Persist to {@see $table} (same shape as {@see \App\Logging\DatabaseLogHandler}).
     * Swallows errors so logging never breaks the request.
     */
    public static function write(Level $level, string $channel, string $message, array $context = [], ?array $extra = null): void
    {
        try {
            static::query()->create([
                'level' => $level->value,
                'level_name' => $level->getName(),
                'channel' => $channel,
                'message' => $message,
                'context' => $context === [] ? null : $context,
                'extra' => $extra === null || $extra === [] ? null : $extra,
                'logged_at' => now(),
            ]);
        } catch (\Throwable) {
            // Avoid recursive or DB failures taking down the app.
        }
    }

    public static function info(string $channel, string $message, array $context = []): void
    {
        self::write(Level::Info, $channel, $message, $context);
    }

    public static function error(string $channel, string $message, array $context = []): void
    {
        self::write(Level::Error, $channel, $message, $context);
    }

    public static function warning(string $channel, string $message, array $context = []): void
    {
        self::write(Level::Warning, $channel, $message, $context);
    }
}
