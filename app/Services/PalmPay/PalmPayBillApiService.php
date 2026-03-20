<?php

namespace App\Services\PalmPay;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * PalmPay Biller Reseller API (billers, items, recharge account, create/query order).
 */
class PalmPayBillApiService
{
    public function __construct(
        protected PalmPayAuthService $auth
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function queryBillers(string $sceneCode): array
    {
        $full = $this->withCoreFields([
            'sceneCode' => $sceneCode,
        ]);
        $signature = $this->auth->signPayload($full);

        return $this->postBillEndpoint('/api/v2/bill-payment/biller/query', $full, $signature);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function queryItems(string $sceneCode, string $billerId): array
    {
        $full = $this->withCoreFields([
            'sceneCode' => $sceneCode,
            'billerId' => $billerId,
        ]);
        $signature = $this->auth->signPayload($full);

        return $this->postBillEndpoint('/api/v2/bill-payment/item/query', $full, $signature);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function queryRechargeAccount(string $sceneCode, string $rechargeAccount, array $extra = []): array
    {
        $full = $this->withCoreFields(array_merge([
            'sceneCode' => $sceneCode,
            'rechargeAccount' => $rechargeAccount,
        ], $extra));
        $signature = $this->auth->signPayload($full);

        $data = $this->postBillEndpoint('/api/v2/bill-payment/rechargeaccount/query', $full, $signature);

        return is_array($data) ? $data : [];
    }

    /**
     * @param  array<string, mixed>  $body  sceneCode, outOrderNo, amount (kobo), notifyUrl, billerId, itemId, rechargeAccount, etc.
     * @return array<string, mixed>
     */
    public function createBillOrder(array $body): array
    {
        $full = $this->withCoreFields($body);
        $signature = $this->auth->signPayload($full);

        return $this->postWrapped('/api/v2/bill-payment/order/create', $full, $signature);
    }

    /**
     * @return array<string, mixed>
     */
    public function queryBillOrder(string $sceneCode, ?string $outOrderNo = null, ?string $orderNo = null): array
    {
        if (! $outOrderNo && ! $orderNo) {
            throw new RuntimeException('outOrderNo or orderNo is required.');
        }
        $full = $this->withCoreFields(array_filter([
            'sceneCode' => $sceneCode,
            'outOrderNo' => $outOrderNo,
            'orderNo' => $orderNo,
        ]));
        $signature = $this->auth->signPayload($full);

        return $this->postWrapped('/api/v2/bill-payment/order/query', $full, $signature);
    }

    /**
     * Bill list endpoints may return a bare array in `data` or the API may wrap billers.
     *
     * @return array<int, array<string, mixed>>
     */
    private function postBillEndpoint(string $path, array $json, string $signature): array
    {
        $url = $this->auth->resolvedBaseUrl().$path;
        $response = Http::timeout(Config::get('palmpay.timeout', 30))
            ->withHeaders($this->billHeaders($signature))
            ->asJson()
            ->post($url, $json);

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new RuntimeException('Invalid PalmPay response.');
        }

        if (($payload['respCode'] ?? null) === '00000000' && isset($payload['data'])) {
            $data = $payload['data'];

            return is_array($data) ? $data : [];
        }

        if (($payload['respCode'] ?? null) !== null && ($payload['respCode'] ?? '') !== '00000000') {
            throw new RuntimeException($payload['respMsg'] ?? 'PalmPay bill API error');
        }

        // Some environments return an array at root
        if (array_is_list($payload)) {
            return $payload;
        }

        throw new RuntimeException($payload['respMsg'] ?? 'Unexpected PalmPay bill API response');
    }

    /**
     * @return array<string, mixed>
     */
    private function postWrapped(string $path, array $json, string $signature): array
    {
        $url = $this->auth->resolvedBaseUrl().$path;
        $response = Http::timeout(Config::get('palmpay.timeout', 30))
            ->withHeaders($this->billHeaders($signature))
            ->asJson()
            ->post($url, $json);

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new RuntimeException('Invalid PalmPay response.');
        }

        if (($payload['respCode'] ?? '') !== '00000000') {
            throw new RuntimeException($payload['respMsg'] ?? 'PalmPay bill API error');
        }

        if (! isset($payload['data']) || ! is_array($payload['data'])) {
            throw new RuntimeException('PalmPay returned no data.');
        }

        return $payload['data'];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function withCoreFields(array $body): array
    {
        return array_merge($body, [
            'requestTime' => $this->auth->requestTimeMs(),
            'version' => Config::get('palmpay.version', 'V2'),
            'nonceStr' => $this->auth->generateNonce(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function billHeaders(string $signature): array
    {
        $apiKey = Config::get('palmpay.api_key') ?: Config::get('palmpay.app_id');

        return [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer '.$apiKey,
            'Signature' => $signature,
            'CountryCode' => Config::get('palmpay.country_code', 'NG'),
        ];
    }
}
