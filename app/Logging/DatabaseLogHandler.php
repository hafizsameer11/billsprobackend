<?php

namespace App\Logging;

use App\Models\ApplicationLog;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;

class DatabaseLogHandler extends AbstractProcessingHandler
{
    protected function write(LogRecord $record): void
    {
        try {
            ApplicationLog::query()->create([
                'level' => $record->level->value,
                'level_name' => $record->level->getName(),
                'channel' => $record->channel,
                'message' => $record->message,
                'context' => $record->context !== [] ? $record->context : null,
                'extra' => $record->extra !== [] ? $record->extra : null,
                'logged_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Avoid recursive logging failures when DB is unavailable.
        }
    }
}
