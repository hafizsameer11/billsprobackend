<?php

namespace App\Services\Tatum;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TatumClient
{
    public function __construct(
        protected string $apiKey,
        protected string $baseUrlV3,
        protected string $baseUrlV4,
        protected int $timeout = 30
    ) {}

    public static function fromConfig(): self
    {
        $key = (string) config('tatum.api_key', '');
        if ($key === '') {
            throw new RuntimeException('TATUM_API_KEY is not configured.');
        }

        return new self(
            $key,
            (string) config('tatum.base_url_v3'),
            (string) config('tatum.base_url_v4'),
            (int) config('tatum.timeout', 30)
        );
    }

    /**
     * @return array{mnemonic?: string, xpub?: string, address: string, privateKey?: string, secret?: string}
     */
    public function createWallet(string $blockchain): array
    {
        $normalized = strtolower($blockchain);

        if ($normalized === 'xrp' || $normalized === 'ripple') {
            $data = $this->getJsonV3('/xrp/account');

            return [
                'address' => $data['address'] ?? '',
                'secret' => $data['secret'] ?? '',
                'privateKey' => $data['secret'] ?? '',
            ];
        }

        return $this->getJsonV3('/'.$normalized.'/wallet');
    }

    public function generateAddress(string $blockchain, string $xpub, int $index): string
    {
        $normalized = strtolower($blockchain);
        $xpubSegment = rawurlencode($xpub);
        $data = $this->getJsonV3('/'.$normalized.'/address/'.$xpubSegment.'/'.$index);

        return (string) ($data['address'] ?? '');
    }

    public function generatePrivateKey(string $blockchain, string $mnemonic, int $index): string
    {
        $normalized = strtolower($blockchain);
        $data = $this->postJsonV3('/'.$normalized.'/wallet/priv', [
            'mnemonic' => $mnemonic,
            'index' => $index,
        ]);

        return (string) ($data['key'] ?? '');
    }

    /**
     * @param  'INCOMING_NATIVE_TX'|'INCOMING_FUNGIBLE_TX'|'ADDRESS_EVENT'  $type
     * @param  array{finality?: 'confirmed'|'final'}  $options
     * @return array<string, mixed>
     */
    public function registerAddressWebhookV4(
        string $address,
        string $blockchain,
        string $webhookUrl,
        string $type = 'INCOMING_NATIVE_TX',
        array $options = []
    ): array {
        $chain = TatumChainMapper::toV4Chain($blockchain);
        $payload = [
            'type' => $type,
            'attr' => [
                'address' => $address,
                'chain' => $chain,
                'url' => $webhookUrl,
            ],
        ];
        if (isset($options['finality'])) {
            $payload['finality'] = $options['finality'];
        }
        $payload = $this->sanitizeV4Payload($payload);

        return $this->postJsonV4('/subscription', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getJsonV3(string $path): array
    {
        try {
            $response = Http::withHeaders($this->headers())
                ->timeout($this->timeout)
                ->get($this->baseUrlV3.$path);

            $response->throw();

            return $response->json() ?? [];
        } catch (RequestException $e) {
            throw new RuntimeException(
                'Tatum V3 GET '.$path.' failed: '.($e->response?->body() ?? $e->getMessage()),
                0,
                $e
            );
        }
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    protected function postJsonV3(string $path, array $body): array
    {
        try {
            $response = Http::withHeaders($this->headers())
                ->timeout($this->timeout)
                ->post($this->baseUrlV3.$path, $body);

            $response->throw();

            return $response->json() ?? [];
        } catch (RequestException $e) {
            throw new RuntimeException(
                'Tatum V3 POST '.$path.' failed: '.($e->response?->body() ?? $e->getMessage()),
                0,
                $e
            );
        }
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    protected function postJsonV4(string $path, array $body): array
    {
        $body = $this->sanitizeV4Payload($body);

        try {
            $response = Http::withHeaders($this->headers())
                ->timeout($this->timeout)
                ->post($this->baseUrlV4.$path, $body);

            $response->throw();

            return $response->json() ?? [];
        } catch (RequestException $e) {
            throw new RuntimeException(
                'Tatum V4 POST '.$path.' failed: '.($e->response?->body() ?? $e->getMessage()),
                0,
                $e
            );
        }
    }

    /**
     * @return array<string, string>
     */
    protected function headers(): array
    {
        return [
            'x-api-key' => $this->apiKey,
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function sanitizeV4Payload(array $payload): array
    {
        unset($payload['contract_address'], $payload['contractAddress']);

        if (isset($payload['attr']) && is_array($payload['attr'])) {
            unset($payload['attr']['contract_address'], $payload['attr']['contractAddress']);
        }

        return $payload;
    }
}
