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

        $rawPrivateKey = $this->rawPrivateKey();
        $privateKey = $this->resolvedPrivateKey();
        if ($privateKey === null) {
            if ($rawPrivateKey === '') {
                throw new RuntimeException('PALMPAY_PRIVATE_KEY is not configured.');
            }
            throw new RuntimeException('PALMPAY_PRIVATE_KEY is invalid. Provide PEM or base64-encoded PKCS8 private key.');
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

        $publicKey = $this->resolvedPublicKey();
        if ($publicKey === null) {
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

    private function rawPrivateKey(): string
    {
        $key = (string) Config::get('palmpay.private_key', '');
        $key = trim(str_replace('\\n', "\n", $key));
        $key = trim($key, "\"'");

        return $key;
    }

    /**
     * @return resource|\OpenSSLAsymmetricKey|null
     */
    private function resolvedPrivateKey()
    {
        $key = $this->rawPrivateKey();

        if ($key === '') {
            return null;
        }

        // 1) PEM directly
        if (str_contains($key, 'BEGIN')) {
            $res = openssl_pkey_get_private($key);
            if ($res !== false) {
                return $res;
            }
        }

        // 2) Base64 body / DER encoded key
        $keyNoWs = preg_replace('/\s+/', '', $key) ?? '';
        $der = base64_decode($keyNoWs, true);
        if ($der !== false && $der !== '') {
            $pem = "-----BEGIN PRIVATE KEY-----\n".
                chunk_split(base64_encode($der), 64, "\n").
                "-----END PRIVATE KEY-----\n";
            $res = openssl_pkey_get_private($pem);
            if ($res !== false) {
                return $res;
            }
        }

        // 3) Last attempt: interpret raw as body text and wrap
        $pemFromBody = "-----BEGIN PRIVATE KEY-----\n".
            chunk_split($keyNoWs, 64, "\n").
            "-----END PRIVATE KEY-----\n";
        $res = openssl_pkey_get_private($pemFromBody);
        if ($res === false) {
            return null;
        }

        return $res;
    }

    /**
     * @return resource|\OpenSSLAsymmetricKey|null
     */
    private function resolvedPublicKey()
    {
        $key = (string) Config::get('palmpay.public_key', '');
        $key = trim(str_replace('\\n', "\n", $key));
        $key = trim($key, "\"'");
        if ($key === '') {
            return null;
        }

        // 1) PEM directly
        if (str_contains($key, 'BEGIN')) {
            $res = openssl_pkey_get_public($key);
            if ($res !== false) {
                return $res;
            }
        }

        // 2) Base64 body / DER -> PEM
        $keyNoWs = preg_replace('/\s+/', '', $key) ?? '';
        $der = base64_decode($keyNoWs, true);
        if ($der !== false && $der !== '') {
            $pem = "-----BEGIN PUBLIC KEY-----\n".
                chunk_split(base64_encode($der), 64, "\n").
                "-----END PUBLIC KEY-----\n";
            $res = openssl_pkey_get_public($pem);
            if ($res !== false) {
                return $res;
            }
        }

        // 3) Raw body wrapped as PEM
        $pemFromBody = "-----BEGIN PUBLIC KEY-----\n".
            chunk_split($keyNoWs, 64, "\n").
            "-----END PUBLIC KEY-----\n";
        $res = openssl_pkey_get_public($pemFromBody);
        if ($res === false) {
            return null;
        }

        return $res;
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
