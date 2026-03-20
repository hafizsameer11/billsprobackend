<?php

namespace App\Services\VirtualCard;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class BsiCardsClient
{
    /**
     * @throws BsiCardsApiException
     */
    public function createMerchantMasterCard(array $payload): array
    {
        return $this->requestMerchant('merchant_master_create', $payload);
    }

    /**
     * @throws BsiCardsApiException
     */
    public function getMerchantMasterCards(array $payload): array
    {
        return $this->requestMerchant('merchant_master_get_all', $payload);
    }

    /**
     * @throws BsiCardsApiException
     */
    public function getMerchantMasterCard(array $payload): array
    {
        return $this->requestMerchant('merchant_master_get_card', $payload);
    }

    /**
     * @throws BsiCardsApiException
     */
    public function fundMerchantMasterCard(array $payload): array
    {
        return $this->requestMerchant('merchant_master_fund', $payload);
    }

    /**
     * @throws BsiCardsApiException
     */
    public function blockMerchantMasterCard(array $payload): array
    {
        return $this->requestMerchant('merchant_master_block', $payload);
    }

    /**
     * @throws BsiCardsApiException
     */
    public function unblockMerchantMasterCard(array $payload): array
    {
        return $this->requestMerchant('merchant_master_unblock', $payload);
    }

    /**
     * @throws BsiCardsApiException
     */
    public function terminateMerchantMasterCard(array $payload): array
    {
        return $this->requestMerchant('merchant_master_terminate', $payload);
    }

    /**
     * @throws BsiCardsApiException
     */
    public function merchantMasterTransactions(array $payload): array
    {
        return $this->requestMerchant('merchant_master_transactions', $payload);
    }

    /**
     * @throws BsiCardsApiException
     */
    public function check3ds(array $payload): array
    {
        return $this->requestMerchant('merchant_master_check_3ds', $payload);
    }

    /**
     * @throws BsiCardsApiException
     */
    public function checkWallet(array $payload): array
    {
        return $this->requestMerchant('merchant_master_check_wallet', $payload);
    }

    /**
     * @throws BsiCardsApiException
     */
    public function approve3ds(array $payload): array
    {
        return $this->requestMerchant('merchant_master_approve_3ds', $payload);
    }

    /**
     * @throws BsiCardsApiException
     */
    protected function requestMerchant(string $endpointKey, array $payload): array
    {
        $path = (string) config("bsicards.endpoints.{$endpointKey}");
        $baseUrl = (string) config('bsicards.merchant_base_url');

        if ($path === '') {
            throw new BsiCardsApiException("BSICards endpoint is not configured for key: {$endpointKey}", 500);
        }

        $url = $this->buildUrl($baseUrl, $path);

        try {
            $response = Http::timeout((int) config('bsicards.timeout', 30))
                ->acceptJson()
                ->asJson()
                ->withHeaders([
                    'publickey' => (string) config('bsicards.public_key'),
                    'secretkey' => (string) config('bsicards.secret_key'),
                ])
                ->post($url, $payload);
        } catch (ConnectionException $exception) {
            throw new BsiCardsApiException('Unable to connect to BSICards provider.', 503, [
                'endpoint_key' => $endpointKey,
                'url' => $url,
                'error' => $exception->getMessage(),
            ]);
        }

        $data = $response->json();
        if (!is_array($data)) {
            $data = [];
        }

        if (!$response->ok()) {
            throw new BsiCardsApiException(
                $data['message'] ?? 'BSICards request failed.',
                $response->status(),
                [
                    'endpoint_key' => $endpointKey,
                    'url' => $url,
                    'response' => $data,
                ]
            );
        }

        return $data;
    }

    protected function buildUrl(string $baseUrl, string $path): string
    {
        return rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
    }
}
