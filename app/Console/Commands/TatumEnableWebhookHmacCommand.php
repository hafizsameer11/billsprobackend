<?php

namespace App\Console\Commands;

use App\Services\Tatum\TatumClient;
use Illuminate\Console\Command;

class TatumEnableWebhookHmacCommand extends Command
{
    protected $signature = 'tatum:enable-webhook-hmac
                            {--secret= : HMAC secret (defaults to TATUM_WEBHOOK_HMAC_SECRET from .env)}';

    protected $description = 'Register Tatum webhook HMAC secret (PUT /v4/subscription) for the current API key';

    public function handle(): int
    {
        $secret = (string) ($this->option('secret') ?: config('tatum.hmac_secret', ''));
        if ($secret === '') {
            $this->error('Set TATUM_WEBHOOK_HMAC_SECRET in .env or pass --secret=');
            $this->line('Generate one: openssl rand -base64 48');

            return self::FAILURE;
        }

        try {
            TatumClient::fromConfig()->enableWebhookHmac($secret);
        } catch (\Throwable $e) {
            $this->error('Tatum HMAC registration failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Tatum webhook HMAC enabled for this API key.');
        $this->line('Ensure .env has:');
        $this->line('  TATUM_WEBHOOK_HMAC_SECRET=(same secret)');
        $this->line('  TATUM_VERIFY_WEBHOOK_HMAC=true');
        $this->line('Then run: php artisan config:clear');

        return self::SUCCESS;
    }
}
