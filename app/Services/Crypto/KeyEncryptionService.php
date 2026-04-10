<?php

namespace App\Services\Crypto;

use RuntimeException;

/**
 * AES-256-CBC encryption for secrets at rest (mnemonic, private keys).
 * Format: iv_hex:ciphertext_hex (same pattern as the reference Node integration).
 */
class KeyEncryptionService
{
    protected const CIPHER = 'AES-256-CBC';

    public function __construct(
        protected string $keyBinary
    ) {}

    public static function fromConfig(): self
    {
        $material = (string) env('ENCRYPTION_KEY', '');
        if ($material === '') {
            $appKey = (string) config('app.key', '');
            if ($appKey !== '') {
                // Fallback to Laravel APP_KEY so OTP/wallet bootstrap works without a separate key.
                $material = 'app-key-fallback:'.$appKey;
            } elseif (config('tatum.use_mock')) {
                $material = 'mock-derive-'.$appKey;
            } else {
                throw new RuntimeException('ENCRYPTION_KEY or APP_KEY must be set for crypto key encryption.');
            }
        }

        // Derive 32-byte key from any string length
        $binary = hash('sha256', $material, true);
        if (strlen($binary) !== 32) {
            throw new RuntimeException('Invalid encryption key derivation.');
        }

        return new self($binary);
    }

    public function encrypt(string $plain): string
    {
        $iv = random_bytes(openssl_cipher_iv_length(self::CIPHER));
        $encrypted = openssl_encrypt($plain, self::CIPHER, $this->keyBinary, OPENSSL_RAW_DATA, $iv);
        if ($encrypted === false) {
            throw new RuntimeException('Encryption failed.');
        }

        return bin2hex($iv).':'.bin2hex($encrypted);
    }

    public function decrypt(string $payload): string
    {
        $parts = explode(':', $payload, 2);
        if (count($parts) !== 2) {
            throw new RuntimeException('Invalid encrypted payload format.');
        }
        [$ivHex, $cipherHex] = $parts;
        $iv = hex2bin($ivHex);
        $cipher = hex2bin($cipherHex);
        if ($iv === false || $cipher === false) {
            throw new RuntimeException('Invalid hex in encrypted payload.');
        }

        $plain = openssl_decrypt($cipher, self::CIPHER, $this->keyBinary, OPENSSL_RAW_DATA, $iv);
        if ($plain === false) {
            throw new RuntimeException('Decryption failed.');
        }

        return $plain;
    }
}
