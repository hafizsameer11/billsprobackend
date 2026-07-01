<?php

namespace App\Services\Tatum;

use Illuminate\Http\Request;

class TatumWebhookVerifier
{
    public function shouldVerify(): bool
    {
        if (! filter_var(config('tatum.verify_webhook_hmac', true), FILTER_VALIDATE_BOOL)) {
            return false;
        }

        $secret = (string) config('tatum.hmac_secret', '');

        return $secret !== '';
    }

    public function verify(Request $request): bool
    {
        if (! $this->shouldVerify()) {
            return true;
        }

        $received = (string) $request->header('x-payload-hash', '');
        if ($received === '') {
            return false;
        }

        $secret = (string) config('tatum.hmac_secret', '');
        $rawBody = $request->getContent();

        $expectedFromRaw = $this->digest($rawBody, $secret);
        if (hash_equals($expectedFromRaw, $received)) {
            return true;
        }

        // Tatum docs use JSON.stringify(parsed body); fallback for servers that normalize JSON.
        if ($rawBody !== '') {
            $decoded = json_decode($rawBody, true);
            if (is_array($decoded)) {
                $canonical = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (is_string($canonical) && hash_equals($this->digest($canonical, $secret), $received)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function digest(string $payload, string $secret): string
    {
        return base64_encode(hash_hmac('sha512', $payload, $secret, true));
    }
}
