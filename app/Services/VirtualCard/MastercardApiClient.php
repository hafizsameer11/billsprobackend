<?php

namespace App\Services\VirtualCard;

use App\Models\ApplicationLog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class MastercardApiClient
{
    /**
     * @throws MastercardApiException
     */
    public function createMerchantMasterCard(array $payload): array
    {
        return $this->requestMerchant('merchant_master_create', $payload);
    }

    /**
     * @throws MastercardApiException
     */
    public function getMerchantMasterCards(array $payload): array
    {
        return $this->requestMerchant('merchant_master_get_all', $payload);
    }

    /**
     * @throws MastercardApiException
     */
    public function getMerchantMasterCard(array $payload): array
    {
        return $this->requestMerchant('merchant_master_get_card', $payload);
    }

    /**
     * @throws MastercardApiException
     */
    public function fundMerchantMasterCard(array $payload): array
    {
        return $this->requestMerchant('merchant_master_fund', $payload);
    }

    /**
     * @throws MastercardApiException
     */
    public function blockMerchantMasterCard(array $payload): array
    {
        return $this->requestMerchant('merchant_master_block', $payload);
    }

    /**
     * @throws MastercardApiException
     */
    public function unblockMerchantMasterCard(array $payload): array
    {
        return $this->requestMerchant('merchant_master_unblock', $payload);
    }

    /**
     * @throws MastercardApiException
     */
    public function terminateMerchantMasterCard(array $payload): array
    {
        return $this->requestMerchant('merchant_master_terminate', $payload);
    }

    /**
     * @throws MastercardApiException
     */
    public function merchantMasterTransactions(array $payload): array
    {
        return $this->requestMerchant('merchant_master_transactions', $payload);
    }

    /**
     * @throws MastercardApiException
     */
    public function check3ds(array $payload): array
    {
        return $this->requestMerchant('merchant_master_check_3ds', $payload);
    }

    /**
     * @throws MastercardApiException
     */
    public function checkWallet(array $payload): array
    {
        return $this->requestMerchant('merchant_master_check_wallet', $payload);
    }

    /**
     * @throws MastercardApiException
     */
    public function approve3ds(array $payload): array
    {
        return $this->requestMerchant('merchant_master_approve_3ds', $payload);
    }

    /**
     * @throws MastercardApiException
     */
    public function spendControl(array $payload): array
    {
        return $this->requestMerchant('merchant_master_spend_control', $payload);
    }

    /**
     * @throws MastercardApiException
     */
    public function deleteSpendControl(array $payload): array
    {
        return $this->requestMerchant('merchant_master_delete_spend_control', $payload);
    }

    /**
     * @throws MastercardApiException
     */
    protected function requestMerchant(string $endpointKey, array $payload): array
    {
        $path = (string) config("mastercard.endpoints.{$endpointKey}");
        $baseUrl = (string) config('mastercard.merchant_base_url');

        if ($path === '') {
            throw new MastercardApiException("Virtual card API endpoint is not configured for key: {$endpointKey}", 500);
        }

        $url = $this->buildUrl($baseUrl, $path);

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
            ApplicationLog::warning('mastercard_api', 'mastercard_api.connection_failed', [
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

            ApplicationLog::warning('mastercard_api', 'mastercard_api.request_failed', $logContext);

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

    protected function buildUrl(string $baseUrl, string $path): string
    {
        return rtrim($baseUrl, '/').'/'.ltrim($path, '/');
    }
}
