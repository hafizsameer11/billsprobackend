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

        $privateKey = $this->normalizedPrivateKey();
        if ($privateKey === '') {
            throw new RuntimeException('PALMPAY_PRIVATE_KEY is not configured.');
        }

        $signature = '';
        $ok = openssl_sign($md5Upper, $signature, $privateKey, OPENSSL_ALGO_SHA1);
        if (! $ok) {
            throw new RuntimeException('OpenSSL could not sign PalmPay payload.');
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

    private function normalizedPrivateKey(): string
    {
        $key = (string) Config::get('palmpay.private_key', '');
        $key = str_replace('\\n', "\n", $key);

        return trim($key);
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
