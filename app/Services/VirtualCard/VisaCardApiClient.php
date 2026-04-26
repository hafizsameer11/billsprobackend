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
        return $this->request('visa_get_card', $payload);
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

        $data['message'] = $this->normalizeMessage($data['message'] ?? null);

        return $data;
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
