<?php

namespace App\Logging;

use Illuminate\Support\Facades\DB;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;

class DatabaseLogHandler extends AbstractProcessingHandler
{
    protected function write(LogRecord $record): void
    {
        try {
            DB::table('application_logs')->insert([
                'level' => $record->level->value,
                'level_name' => $record->level->getName(),
                'channel' => $record->channel,
                'message' => $record->message,
                'context' => $record->context !== [] ? json_encode($record->context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                'extra' => $record->extra !== [] ? json_encode($record->extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                'logged_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Avoid recursive logging failures when DB is unavailable.
        }
    }
}

