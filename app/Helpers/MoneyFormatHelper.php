<?php

namespace App\Helpers;

/**
 * Human-readable money for push / in-app notification copy.
 * Fiat (NGN, USD, …): symbol + thousands separators, no decimals.
 * Crypto: currency code + trimmed fractional digits (up to 8).
 */
class MoneyFormatHelper
{
    /** @var list<string> */
    private const CRYPTO_CURRENCIES = [
        'BTC', 'ETH', 'USDT', 'USDC', 'TRX', 'SOL', 'BNB', 'BSC', 'DOGE', 'XRP', 'LTC', 'MATIC', 'TON',
    ];

    public static function isCryptoCurrency(?string $currency): bool
    {
        $code = strtoupper(trim((string) $currency));

        return $code !== '' && in_array($code, self::CRYPTO_CURRENCIES, true);
    }

    /**
     * e.g. NGN 5000.00000000 → ₦5,000 | USDT 1.25000000 → USDT 1.25
     */
    public static function format(float|int|string|null $amount, ?string $currency = 'NGN'): string
    {
        $code = strtoupper(trim((string) ($currency ?: 'NGN')));
        $value = is_numeric($amount) ? (float) $amount : 0.0;

        if (self::isCryptoCurrency($code)) {
            $trimmed = self::formatCryptoAmount($value);

            return "{$code} {$trimmed}";
        }

        if ($code === 'NGN') {
            return '₦'.number_format((float) round($value), 0, '.', ',');
        }

        if ($code === 'USD') {
            return '$'.number_format((float) round($value), 0, '.', ',');
        }

        return $code.' '.number_format((float) round($value), 0, '.', ',');
    }

    private static function formatCryptoAmount(float $value): string
    {
        $formatted = number_format($value, 8, '.', '');
        $formatted = rtrim(rtrim($formatted, '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }
}
