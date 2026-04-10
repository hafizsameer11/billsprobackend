<?php

namespace App\Services\PalmPay;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * PalmPay merchant payout (bank transfer to user's account).
 *
 * @see https://open-gw-daily.palmpay-inc.com — /api/v2/merchant/payment/payout
 */
class PalmPayPayoutService
{
    public function __construct(
        protected PalmPayAuthService $auth
    ) {}

    /**
     * @param  array<string, mixed>  $body  orderId, payeeBankCode, payeeBankAccNo, amount (cents), currency, notifyUrl, etc.
     * @return array<string, mixed>
     */
    public function initiatePayout(array $body): array
    {
        $full = $this->withCoreFields($body);
        $signature = $this->auth->signPayload($full);
        $url = $this->auth->resolvedBaseUrl().'/api/v2/merchant/payment/payout';

        return $this->postExpectData($url, $full, $signature);
    }

    /**
     * @return array<string, mixed>
     */
    public function queryPayStatus(?string $orderId = null, ?string $orderNo = null): array
    {
        if (! $orderId && ! $orderNo) {
            throw new RuntimeException('orderId or orderNo is required.');
        }
        $full = $this->withCoreFields(array_filter([
            'orderId' => $orderId,
            'orderNo' => $orderNo,
        ]));
        $signature = $this->auth->signPayload($full);
        $url = $this->auth->resolvedBaseUrl().'/api/v2/merchant/payment/queryPayStatus';

        return $this->postExpectData($url, $full, $signature);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function queryBankList(int $businessType = 0): array
    {
        $full = $this->withCoreFields([
            'businessType' => $businessType,
        ]);
        $signature = $this->auth->signPayload($full);
        $url = $this->auth->resolvedBaseUrl().'/api/v2/general/merchant/queryBankList';

        $data = $this->postExpectData($url, $full, $signature);

        return isset($data['bankList']) && is_array($data['bankList']) ? $data['bankList'] : (array_is_list($data) ? $data : []);
    }

    /**
     * @return array<string, mixed>
     */
    public function verifyAccount(string $bankCode, string $accountNumber): array
    {
        $normalizedAccount = preg_replace('/\D/', '', $accountNumber) ?? '';
        if ($normalizedAccount === '') {
            throw new RuntimeException('Account number is required.');
        }

        if ($bankCode === '100033') {
            $full = $this->withCoreFields([
                // PalmPay account verification expects palmpayAccNo.
                'palmpayAccNo' => $normalizedAccount,
            ]);
            $signature = $this->auth->signPayload($full);
            $url = $this->auth->resolvedBaseUrl().'/api/v2/payment/merchant/payout/queryAccount';

            return $this->postExpectData($url, $full, $signature);
        }

        // queryBankAccount is sensitive to payload fields/version.
        $full = $this->withCoreFields([
            // PalmPay expects V1.1 on this endpoint.
            'version' => 'V1.1',
            'bankCode' => $bankCode,
            'bankAccNo' => $normalizedAccount,
        ]);
        $signature = $this->auth->signPayload($full);
        $url = $this->auth->resolvedBaseUrl().'/api/v2/payment/merchant/payout/queryBankAccount';

        return $this->postExpectData($url, $full, $signature);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function withCoreFields(array $body): array
    {
        if (isset($body['payeeBankAccNo']) && is_string($body['payeeBankAccNo'])) {
            $body['payeeBankAccNo'] = preg_replace('/\D/', '', $body['payeeBankAccNo']) ?? '';
        }

        return array_merge([
            'requestTime' => $this->auth->requestTimeMs(),
            'version' => Config::get('palmpay.version', 'V2'),
            'nonceStr' => $this->auth->generateNonce(),
        ], $body);
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
            throw new RuntimeException($data['respMsg'] ?? 'PalmPay payout request failed');
        }

        if (! isset($data['data']) || ! is_array($data['data'])) {
            throw new RuntimeException('PalmPay returned no data.');
        }

        return $data['data'];
    }
}
