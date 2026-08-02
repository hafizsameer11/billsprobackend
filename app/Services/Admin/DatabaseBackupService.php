<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class DatabaseBackupService
{
    /**
     * Create a MySQL dump under storage/app/backups.
     */
    public function backupMysql(string $label = 'manual'): string
    {
        if (config('database.default') !== 'mysql') {
            throw new RuntimeException('Database backup only supports MySQL.');
        }

        $connection = config('database.connections.mysql');
        $database = (string) ($connection['database'] ?? '');
        if ($database === '') {
            throw new RuntimeException('MySQL database name is not configured.');
        }

        $dir = storage_path('app/backups');
        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        $filename = sprintf(
            '%s_%s_%s.sql.gz',
            $database,
            preg_replace('/[^a-z0-9_-]+/i', '-', $label),
            now()->format('Ymd_His')
        );
        $path = $dir.'/'.$filename;

        $host = (string) ($connection['host'] ?? '127.0.0.1');
        $port = (string) ($connection['port'] ?? '3306');
        $username = (string) ($connection['username'] ?? '');
        $password = (string) ($connection['password'] ?? '');

        $command = sprintf(
            'mysqldump --single-transaction --quick --lock-tables=false -h %s -P %s -u %s %s | gzip > %s',
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($username),
            escapeshellarg($database),
            escapeshellarg($path)
        );

        $env = $password !== '' ? ['MYSQL_PWD' => $password] : [];

        $result = Process::env($env)->timeout(600)->run(['bash', '-c', $command]);
        if (! $result->successful() || ! File::exists($path) || File::size($path) === 0) {
            throw new RuntimeException(
                'Database backup failed: '.trim($result->errorOutput() ?: $result->output() ?: 'unknown error')
            );
        }

        return $path;
    }
}
