<?php

namespace App\Services\VirtualCard;

use App\Models\ApplicationLog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Pagocards Visa reseller API — same credentials and base URL as {@see MastercardApiClient}.
 */
class VisaCardApiClient
{
    /** Pagocards Visa amounts (balance, tx amounts, merchant amounts) are scaled: 1 USD = 1e6 integer units. */
    private const VISA_USD_MICRO_UNIT = 1_000_000.0;

    /**
     * @throws MastercardApiException
     */
    public function createCard(array $payload): array
    {
        return $this->request('visa_create', $payload);
    }

    /**
     * @throws MastercardApiException
     */
    public function getAllCards(array $payload): array
    {
        return $this->request('visa_get_all', $payload);
    }

    /**
     * @throws MastercardApiException
     */
    public function getCardDetails(array $payload): array
    {
        $data = $this->request('visa_get_card', $payload);
        $normalized = $this->normalizeVisaGetCardResponse($data);
        if (array_key_exists('success', $normalized) && $normalized['success'] === false) {
            throw new MastercardApiException(
                $this->normalizeMessage($normalized['message'] ?? null),
                422,
                [
                    'endpoint_key' => 'visa_get_card',
                    'response' => $data,
                ]
            );
        }

        return $normalized;
    }

    /**
     * @throws MastercardApiException
     */
    public function fundCard(array $payload): array
    {
        return $this->request('visa_fund', $payload);
    }

    /**
     * @throws MastercardApiException
     */
    public function blockCard(array $payload): array
    {
        return $this->request('visa_block', $payload);
    }

    /**
     * @throws MastercardApiException
     */
    public function unblockCard(array $payload): array
    {
        return $this->request('visa_unblock', $payload);
    }

    /**
     * @throws MastercardApiException
     */
    protected function request(string $endpointKey, array $payload): array
    {
        $path = (string) config("mastercard.endpoints.{$endpointKey}");
        $baseUrl = (string) config('mastercard.merchant_base_url');

        if ($path === '') {
            throw new MastercardApiException("Visa card API endpoint is not configured for key: {$endpointKey}", 500);
        }

        $url = rtrim($baseUrl, '/').'/'.ltrim($path, '/');

        try {
            $response = Http::timeout((int) config('mastercard.timeout', 30))
                ->acceptJson()
                ->asJson()
                ->withHeaders([
                    'publickey' => (string) config('mastercard.public_key'),
                    'secretkey' => (string) config('mastercard.secret_key'),
                ])
                ->post($url, $payload);
        } catch (ConnectionException $exception) {
            ApplicationLog::warning('visacard_api', 'visacard_api.connection_failed', [
                'endpoint_key' => $endpointKey,
                'url' => $url,
                'error' => $exception->getMessage(),
            ]);

            throw new MastercardApiException('Unable to connect to virtual card provider.', 503, [
                'endpoint_key' => $endpointKey,
                'url' => $url,
                'error' => $exception->getMessage(),
            ]);
        }

        $data = $response->json();
        if (! is_array($data)) {
            $data = [];
        }

        if (! $response->ok()) {
            $message = $this->normalizeMessage($data['message'] ?? null);
            $logContext = [
                'endpoint_key' => $endpointKey,
                'url' => $url,
                'http_status' => $response->status(),
                'response_json' => $data,
            ];
            if ($data === []) {
                $logContext['response_body_snippet'] = Str::limit((string) $response->body(), 800);
            }

            ApplicationLog::warning('visacard_api', 'visacard_api.request_failed', $logContext);

            throw new MastercardApiException(
                $message,
                $response->status(),
                [
                    'endpoint_key' => $endpointKey,
                    'url' => $url,
                    'response' => $data,
                ]
            );
        }

        // Visa getcard returns `{ "secure": { "success", "message", "data": { "details", "transactions" } } }`
        // without a top-level `message`; avoid turning that into a bogus "request failed" string.
        if ($endpointKey !== 'visa_get_card') {
            $data['message'] = $this->normalizeMessage($data['message'] ?? null);
        }

        return $data;
    }

    /**
     * Flatten Pagocards Visa getcard JSON into the same `data.*` shape {@see VirtualCardService} expects
     * (aligned with Mastercard getcarddetails-style consumers).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function normalizeVisaGetCardResponse(array $data): array
    {
        $secure = $data['secure'] ?? null;
        if (! is_array($secure)) {
            return $data;
        }

        $inner = $secure['data'] ?? null;
        if (! is_array($inner)) {
            $msg = $secure['message'] ?? null;

            return [
                'success' => (bool) ($secure['success'] ?? true),
                'message' => is_string($msg) && $msg !== ''
                    ? $msg
                    : 'Card details retrieved successfully.',
                'data' => [],
            ];
        }

        $details = $inner['details'] ?? [];
        $details = is_array($details) ? $details : [];

        $transactions = $inner['transactions'] ?? [];
        $transactions = is_array($transactions) ? array_values($transactions) : [];
        $transactions = array_map(function ($row) {
            return is_array($row) ? $this->scaleVisaTransactionRowFromMicroUsd($row) : $row;
        }, $transactions);

        $balanceUsd = null;
        $rawBal = $details['balance_amount'] ?? null;
        if (is_numeric($rawBal)) {
            // Pagocards Visa: balance_amount is micro-USD (1e6 per $1), same as transaction amounts.
            $balanceUsd = (float) $rawBal / self::VISA_USD_MICRO_UNIT;
        }

        $flat = array_merge($details, [
            'transactions' => $transactions,
        ]);
        if ($balanceUsd !== null) {
            $flat['balance'] = $balanceUsd;
        }

        $msg = $secure['message'] ?? null;

        return [
            'success' => (bool) ($secure['success'] ?? true),
            'message' => is_string($msg) && $msg !== ''
                ? $msg
                : 'Card details retrieved successfully.',
            'data' => $flat,
        ];
    }

    /**
     * Convert one Visa getcard transaction row from Pagocards micro-USD to decimal USD for DB / {@see VirtualCardService::normalizeProviderTransaction}.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function scaleVisaTransactionRowFromMicroUsd(array $row): array
    {
        $moneyKeys = [
            'amount', 'transaction_amount', 'value', 'debit_amount', 'debitAmount',
            'merchantAmount', 'merchant_amount', 'total_amount', 'total', 'fee', 'transaction_fee',
            'fee_amount', 'feeAmount',
            'authorized_amount', 'authorizedAmount', 'authorization_amount', 'authorizationAmount',
            'billing_amount', 'billingAmount',
            'settlement_amount', 'settlementAmount',
            // Same scale as other Visa money integers when provider sends micro-USD; small values left as-is by scaler.
            'display_amount', 'displayAmount',
        ];
        $out = $row;
        foreach ($moneyKeys as $key) {
            if (! array_key_exists($key, $out)) {
                continue;
            }
            $out[$key] = $this->scaleVisaMicroUsdScalar($out[$key]);
        }
        if (isset($out['merchant']) && is_array($out['merchant'])) {
            $m = $out['merchant'];
            foreach (['amount', 'transactionAmount', 'authorizedAmount'] as $mk) {
                if (array_key_exists($mk, $m)) {
                    $m[$mk] = $this->scaleVisaMicroUsdScalar($m[$mk]);
                }
            }
            $out['merchant'] = $m;
        }

        return $out;
    }

    /**
     * @return mixed  float|list<mixed>|mixed unchanged non-numeric
     */
    protected function scaleVisaMicroUsdScalar(mixed $raw): mixed
    {
        if ($raw === null || $raw === '') {
            return $raw;
        }
        if (is_array($raw)) {
            return array_map(fn ($v) => $this->scaleVisaMicroUsdScalar($v), $raw);
        }
        if (! is_numeric($raw)) {
            return $raw;
        }
        $n = (float) $raw;
        if (! is_finite($n) || $n === 0.0) {
            return $n;
        }
        $asString = (string) $raw;
        // Already decimal USD from provider (e.g. "9.00", "1.5") — do not scale.
        if (str_contains($asString, '.') || str_contains(strtolower($asString), 'e')) {
            if (abs($n) < 1000.0) {
                return $n;
            }
        }
        // Small integers are treated as whole dollars (legacy / edge); large magnitudes are micro-USD.
        if (abs($n) < 1000.0) {
            return $n;
        }

        return $n / self::VISA_USD_MICRO_UNIT;
    }

    protected function normalizeMessage(mixed $rawMessage): string
    {
        if (is_string($rawMessage) && $rawMessage !== '') {
            return $rawMessage;
        }

        if (is_array($rawMessage)) {
            $flattened = [];
            array_walk_recursive($rawMessage, static function ($value) use (&$flattened): void {
                if (is_scalar($value)) {
                    $flattened[] = (string) $value;
                }
            });

            if ($flattened !== []) {
                return implode('; ', $flattened);
            }

            $encoded = json_encode($rawMessage);
            if (is_string($encoded) && $encoded !== '') {
                return $encoded;
            }
        }

        return 'Virtual card provider request failed.';
    }
}
