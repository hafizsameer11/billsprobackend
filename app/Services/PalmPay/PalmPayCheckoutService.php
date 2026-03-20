<?php

namespace App\Services\PalmPay;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PalmPayCheckoutService
{
    public function __construct(
        protected PalmPayAuthService $auth
    ) {}

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function createOrder(array $body): array
    {
        $full = $this->withCoreFields($body);
        $signature = $this->auth->signPayload($full);
        $url = $this->auth->resolvedBaseUrl().'/api/v2/payment/merchant/createorder';

        return $this->postExpectData($url, $full, $signature);
    }

    /**
     * @return array<string, mixed>
     */
    public function queryOrderStatus(?string $orderId = null, ?string $orderNo = null): array
    {
        if (! $orderId && ! $orderNo) {
            throw new RuntimeException('orderId or orderNo is required.');
        }
        $full = $this->withCoreFields(array_filter([
            'orderId' => $orderId,
            'orderNo' => $orderNo,
        ]));
        $signature = $this->auth->signPayload($full);
        $url = $this->auth->resolvedBaseUrl().'/api/v2/payment/merchant/order/queryStatus';

        return $this->postExpectData($url, $full, $signature);
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
     * @param  array<string, mixed>  $json
     * @return array<string, mixed>
     */
    private function postExpectData(string $url, array $json, string $signature): array
    {
        $response = Http::timeout(Config::get('palmpay.timeout', 30))
            ->withHeaders($this->auth->requestHeaders($signature))
            ->asJson()
            ->post($url, $json);

        $data = $response->json();
        if (! is_array($data)) {
            throw new RuntimeException('Invalid PalmPay response.');
        }

        if (($data['respCode'] ?? '') !== '00000000') {
            throw new RuntimeException($data['respMsg'] ?? 'PalmPay request failed');
        }

        if (! isset($data['data']) || ! is_array($data['data'])) {
            throw new RuntimeException('PalmPay returned no data.');
        }

        return $data['data'];
    }
}
