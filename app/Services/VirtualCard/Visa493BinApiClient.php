<?php

namespace App\Services\VirtualCard;

use App\Models\ApplicationLog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Pagocards 4XXBINs API (493 BIN) — {@see https://pagocards.com/documentation#v1-cards-create}.
 */
class Visa493BinApiClient
{
    private const USD_MICRO_UNIT = 1_000_000.0;

    public function isEnabled(): bool
    {
        return (bool) config('mastercard.visa_493.enabled', true);
    }

    public function productCode(): string
    {
        return (string) config('mastercard.visa_493.product_code', 'us_493_visa_bin');
    }

    /**
     * @param  array{first_name: string, last_name: string, email: string}  $payload
     * @return array<string, mixed>
     *
     * @throws MastercardApiException
     */
    public function createCard(array $payload): array
    {
        $body = array_merge($payload, [
            'product_code' => $this->productCode(),
        ]);

        return $this->requestPost('create', $body);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws MastercardApiException
     */
    public function getCardDetails(string $cardId): array
    {
        $raw = $this->requestGet('get', ['card_id' => $cardId]);

        return $this->normalizeGetCardResponse($raw);
    }

    /**
     * @param  array{email: string, product_code?: string}  $payload
     * @return array<string, mixed>
     *
     * @throws MastercardApiException
     */
    public function getAllCards(array $payload): array
    {
        $body = array_merge($payload, [
            'product_code' => $payload['product_code'] ?? $this->productCode(),
        ]);

        return $this->requestPost('get_all', $body);
    }

    /**
     * @param  array{card_id: string, amount: float|int|string}  $payload
     * @return array<string, mixed>
     *
     * @throws MastercardApiException
     */
    public function fundCard(array $payload): array
    {
        return $this->requestPost('fund', [
            'card_id' => (string) $payload['card_id'],
            'amount' => $payload['amount'],
        ]);
    }

    /**
     * @param  array{card_id: string, amount: float|int|string}  $payload
     * @return array<string, mixed>
     *
     * @throws MastercardApiException
     */
    public function withdrawCard(array $payload): array
    {
        return $this->requestPost('withdraw', [
            'card_id' => (string) $payload['card_id'],
            'amount' => $payload['amount'],
        ]);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws MastercardApiException
     */
    public function blockCard(string $cardId): array
    {
        return $this->requestPost('block', ['card_id' => $cardId]);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws MastercardApiException
     */
    public function unblockCard(string $cardId): array
    {
        return $this->requestPost('unblock', ['card_id' => $cardId]);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws MastercardApiException
     */
    public function terminateCard(string $cardId): array
    {
        return $this->requestPost('terminate', ['card_id' => $cardId]);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws MastercardApiException
     */
    public function getTransactions(string $cardId, int $pageNum = 1): array
    {
        return $this->requestGet('transactions', [
            'card_id' => $cardId,
            'pageNum' => max(1, $pageNum),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws MastercardApiException
     */
    protected function requestPost(string $endpointKey, array $payload): array
    {
        $pathTemplate = (string) config("mastercard.visa_493.endpoints.{$endpointKey}");
        $cardId = (string) ($payload['card_id'] ?? '');
        unset($payload['card_id']);

        $path = $this->resolvePath($pathTemplate, $cardId);
        $url = $this->buildUrl($path);

        return $this->sendRequest('POST', $url, $endpointKey, $payload);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     *
     * @throws MastercardApiException
     */
    protected function requestGet(string $endpointKey, array $params): array
    {
        $pathTemplate = (string) config("mastercard.visa_493.endpoints.{$endpointKey}");
        $cardId = (string) ($params['card_id'] ?? '');
        unset($params['card_id']);

        $path = $this->resolvePath($pathTemplate, $cardId);
        $url = $this->buildUrl($path);

        if ($endpointKey === 'transactions' && isset($params['pageNum'])) {
            $url .= '?pageNum='.(int) $params['pageNum'];
        }

        return $this->sendRequest('GET', $url, $endpointKey);
    }

    protected function resolvePath(string $template, string $cardId): string
    {
        if ($cardId !== '' && str_contains($template, '{card_id}')) {
            return str_replace('{card_id}', rawurlencode($cardId), $template);
        }

        return $template;
    }

    protected function buildUrl(string $path): string
    {
        $baseUrl = (string) config('mastercard.merchant_base_url');

        return rtrim($baseUrl, '/').'/'.ltrim($path, '/');
    }

    /**
     * @return array<string, mixed>
     *
     * @throws MastercardApiException
     */
    protected function sendRequest(string $method, string $url, string $endpointKey, array $payload = []): array
    {
        if ($url === '' || str_ends_with($url, '/')) {
            throw new MastercardApiException("493 BIN API endpoint is not configured for key: {$endpointKey}", 500);
        }

        try {
            $pending = Http::timeout((int) config('mastercard.timeout', 30))
                ->acceptJson()
                ->withHeaders([
                    'publickey' => (string) config('mastercard.public_key'),
                    'secretkey' => (string) config('mastercard.secret_key'),
                ]);

            $response = $method === 'GET'
                ? $pending->get($url)
                : $pending->asJson()->post($url, $payload);
        } catch (ConnectionException $exception) {
            ApplicationLog::warning('visa_493_api', 'visa_493_api.connection_failed', [
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

        $status = strtolower((string) ($data['status'] ?? ''));
        $failed = ! $response->successful() || ($status !== '' && $status === 'failure');

        if ($failed) {
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

            ApplicationLog::warning('visa_493_api', 'visa_493_api.request_failed', $logContext);

            throw new MastercardApiException(
                $message,
                $response->status() ?: 422,
                [
                    'endpoint_key' => $endpointKey,
                    'url' => $url,
                    'response' => $data,
                ]
            );
        }

        if (! isset($data['message']) || $data['message'] === '') {
            $data['message'] = 'Request completed successfully.';
        }

        return $data;
    }

    /**
     * Map 493 GET card JSON into the flat `data.*` shape {@see VirtualCardService} expects.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    protected function normalizeGetCardResponse(array $raw): array
    {
        $details = $raw['data'] ?? $raw;
        if (! is_array($details)) {
            $details = [];
        }

        $balanceUsd = $this->extractBalanceUsd($details);
        $transactions = [];
        if (isset($details['transactions']) && is_array($details['transactions'])) {
            $transactions = array_map(
                fn ($row) => is_array($row) ? $this->scaleTransactionRow($row) : $row,
                array_values($details['transactions'])
            );
        }

        $flat = array_merge($details, [
            'cardid' => $details['card_id'] ?? $details['cardid'] ?? null,
            'card_number' => $details['card_number'] ?? $details['cardnumber'] ?? null,
            'nameoncard' => $details['name_on_card'] ?? $details['nameoncard'] ?? null,
            'transactions' => $transactions,
        ]);

        if ($balanceUsd !== null) {
            $flat['balance'] = $balanceUsd;
        }

        $this->applyExpiryFields($flat, $details);

        return [
            'success' => true,
            'message' => (string) ($raw['message'] ?? 'Card details retrieved successfully.'),
            'data' => $flat,
        ];
    }

    /**
     * Normalize dedicated transactions endpoint for {@see VirtualCardService::syncProviderTransactions}.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public function normalizeTransactionsResponse(array $raw): array
    {
        $rows = data_get($raw, 'data.transactions', []);
        if (! is_array($rows)) {
            $rows = [];
        }

        $transactions = array_map(
            fn ($row) => is_array($row) ? $this->scaleTransactionRow($row) : $row,
            array_values(array_filter($rows, 'is_array'))
        );

        return [
            'success' => true,
            'message' => (string) ($raw['message'] ?? 'Transactions fetched successfully.'),
            'data' => [
                'transactions' => $transactions,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $details
     */
    protected function extractBalanceUsd(array $details): ?float
    {
        $balance = $details['balance'] ?? null;
        if (is_array($balance)) {
            if (isset($balance['display_amount']) && is_numeric($balance['display_amount'])) {
                return (float) $balance['display_amount'];
            }
            if (isset($balance['amount']) && is_numeric($balance['amount'])) {
                return $this->scaleMicroUsdScalar($balance['amount']);
            }
        }

        foreach (['balance', 'display_amount', 'displayAmount'] as $key) {
            if (isset($details[$key]) && is_numeric($details[$key])) {
                return $this->scaleMicroUsdScalar($details[$key]);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $flat
     * @param  array<string, mixed>  $details
     */
    protected function applyExpiryFields(array &$flat, array $details): void
    {
        if (! empty($details['expiry_month']) && ! empty($details['expiry_year'])) {
            $flat['expiry_month'] = str_pad((string) $details['expiry_month'], 2, '0', STR_PAD_LEFT);
            $flat['expiry_year'] = (string) $details['expiry_year'];

            return;
        }

        $expire = (string) ($details['expiredate'] ?? $details['expire_date'] ?? '');
        if (preg_match('/^(\d{2})\/(\d{2,4})$/', $expire, $m)) {
            $flat['expiry_month'] = $m[1];
            $year = $m[2];
            $flat['expiry_year'] = strlen($year) === 2 ? '20'.$year : $year;
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function scaleTransactionRow(array $row): array
    {
        $moneyKeys = [
            'amount', 'display_amount', 'displayAmount', 'feeAmount', 'fee_amount',
            'merchant_amount', 'merchantAmount', 'transCurrencyAmt',
        ];
        $out = $row;
        foreach ($moneyKeys as $key) {
            if (array_key_exists($key, $out)) {
                $out[$key] = $this->scaleMicroUsdScalar($out[$key]);
            }
        }

        if (isset($out['description']) || isset($out['narrative'])) {
            $out['description'] = (string) ($out['description'] ?? $out['narrative'] ?? '');
        }

        return $out;
    }

    protected function scaleMicroUsdScalar(mixed $raw): mixed
    {
        if ($raw === null || $raw === '') {
            return $raw;
        }
        if (! is_numeric($raw)) {
            return $raw;
        }
        $n = (float) $raw;
        if (! is_finite($n) || $n === 0.0) {
            return $n;
        }
        $asString = (string) $raw;
        if (str_contains($asString, '.') || str_contains(strtolower($asString), 'e')) {
            if (abs($n) < 1000.0) {
                return $n;
            }
        }
        if (abs($n) < 1000.0) {
            return $n;
        }

        return $n / self::USD_MICRO_UNIT;
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
        }

        return 'Virtual card provider request failed.';
    }
}
