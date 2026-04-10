<?php

namespace App\Services\PalmPay;

use Illuminate\Support\Facades\Config;
use RuntimeException;

class PalmPayAuthService
{
    public function generateNonce(): string
    {
        return bin2hex(random_bytes(16));
    }

    public function requestTimeMs(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function buildSignString(array $params): string
    {
        $filtered = [];
        foreach ($params as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $filtered[$key] = $value;
        }
        ksort($filtered, SORT_STRING);
        $parts = [];
        foreach ($filtered as $k => $v) {
            $parts[] = $k.'='.trim((string) $v);
        }

        return implode('&', $parts);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function signPayload(array $params): string
    {
        $signString = $this->buildSignString($params);
        $md5Upper = strtoupper(md5($signString));

        $privateKey = $this->resolvedPrivateKey();
        if ($privateKey === null) {
            throw new RuntimeException('PALMPAY_PRIVATE_KEY is not configured.');
        }

        $signature = '';
        $ok = openssl_sign($md5Upper, $signature, $privateKey, OPENSSL_ALGO_SHA1);
        if (! $ok) {
            throw new RuntimeException('OpenSSL could not sign PalmPay payload. Check PALMPAY_PRIVATE_KEY format (PEM or base64-encoded PEM).');
        }

        return base64_encode($signature);
    }

    /**
     * @param  array<string, mixed>  $payload  Raw webhook body (includes sign)
     */
    public function verifyWebhookPayload(array $payload, string $encodedSign): bool
    {
        $encodedSign = rawurldecode($encodedSign);
        $withoutSign = $payload;
        unset($withoutSign['sign']);

        $md5Upper = strtoupper(md5($this->buildSignString($withoutSign)));

        $publicKey = $this->normalizedPublicKey();
        if ($publicKey === '') {
            return false;
        }

        $binary = base64_decode($encodedSign, true);
        if ($binary === false) {
            return false;
        }

        $result = openssl_verify($md5Upper, $binary, $publicKey, OPENSSL_ALGO_SHA1);

        return $result === 1;
    }

    public function requestHeaders(string $signature): array
    {
        $appId = (string) Config::get('palmpay.app_id', '');
        if ($appId === '') {
            throw new RuntimeException('PALMPAY_APP_ID is not configured.');
        }

        return [
            'Accept' => 'application/json, text/plain, */*',
            'Content-Type' => 'application/json',
            'CountryCode' => Config::get('palmpay.country_code', 'NG'),
            'Authorization' => 'Bearer '.$appId,
            'Signature' => $signature,
        ];
    }

    /**
     * @return resource|\OpenSSLAsymmetricKey|null
     */
    private function resolvedPrivateKey()
    {
        $key = (string) Config::get('palmpay.private_key', '');
        $key = trim(str_replace('\\n', "\n", $key));

        if ($key === '') {
            return null;
        }

        $candidate = $key;
        if (! str_contains($candidate, 'BEGIN')) {
            $decoded = base64_decode($candidate, true);
            if (is_string($decoded) && $decoded !== '') {
                $candidate = trim(str_replace('\\n', "\n", $decoded));
            }
        }

        if (! str_contains($candidate, 'BEGIN')) {
            $candidate = "-----BEGIN PRIVATE KEY-----\n".
                chunk_split(preg_replace('/\s+/', '', $candidate) ?? '', 64, "\n").
                "-----END PRIVATE KEY-----";
        }

        $res = openssl_pkey_get_private($candidate);
        if ($res === false) {
            return null;
        }

        return $res;
    }

    private function normalizedPublicKey(): string
    {
        $key = (string) Config::get('palmpay.public_key', '');
        $key = str_replace('\\n', "\n", $key);

        return trim($key);
    }

    public function resolvedBaseUrl(): string
    {
        $override = Config::get('palmpay.base_url');
        if (is_string($override) && $override !== '') {
            return rtrim($override, '/');
        }

        $env = Config::get('palmpay.environment', 'sandbox');

        return $env === 'production'
            ? 'https://open-gw-prod.palmpay-inc.com'
            : 'https://open-gw-daily.palmpay-inc.com';
    }
}
