<?php

namespace App\Services\CheckMyNinBvn;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class CheckMyNinBvnClient
{
    public function __construct(
        protected string $apiKey,
        protected string $baseUrl,
        protected int $timeout = 60
    ) {}

    public static function fromConfig(): self
    {
        $key = (string) config('checkmyninbvn.api_key', '');
        if ($key === '') {
            throw new RuntimeException('CHECKMYNINBVN_API_KEY is not configured.');
        }

        return new self(
            $key,
            (string) config('checkmyninbvn.base_url', 'https://checkmyninbvn.com.ng/api'),
            (int) config('checkmyninbvn.timeout', 60)
        );
    }

    /**
     * @return array{success: bool, report_id: ?string, message: string, data: ?array, raw: array}
     */
    public function verifyNin(string $nin): array
    {
        return $this->verify('nin-verification', ['nin' => $nin, 'consent' => true]);
    }

    /**
     * @return array{success: bool, report_id: ?string, message: string, data: ?array, raw: array}
     */
    public function verifyBvn(string $bvn): array
    {
        return $this->verify('bvn-verification', ['bvn' => $bvn, 'consent' => true]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, report_id: ?string, message: string, data: ?array, raw: array}
     */
    protected function verify(string $path, array $payload): array
    {
        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'x-api-key' => $this->apiKey,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ])
                ->post("{$this->baseUrl}/{$path}", $payload);

            $raw = $response->json() ?? [];

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'report_id' => $raw['reportID'] ?? null,
                    'message' => $raw['message'] ?? "Identity verification failed ({$response->status()}).",
                    'data' => null,
                    'raw' => $raw,
                ];
            }

            $status = strtolower((string) ($raw['status'] ?? ''));
            $person = $raw['data']['data'] ?? $raw['data'] ?? null;
            if (is_array($person)) {
                $person = $this->stripLargeBinaryFields($person);
            }

            return [
                'success' => $status === 'success',
                'report_id' => $raw['reportID'] ?? null,
                'message' => (string) ($raw['message'] ?? ''),
                'data' => is_array($person) ? $person : null,
                'raw' => $this->stripLargeBinaryFields($raw),
            ];
        } catch (\Throwable $e) {
            Log::error('CheckMyNinBvn verification error: '.$e->getMessage(), [
                'path' => $path,
            ]);

            return [
                'success' => false,
                'report_id' => null,
                'message' => 'Unable to reach identity verification provider. Please try again.',
                'data' => null,
                'raw' => [],
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function stripLargeBinaryFields(array $data): array
    {
        foreach (['photo', 'base64Image'] as $key) {
            if (!empty($data[$key]) && is_string($data[$key])) {
                $data[$key] = '[BASE64_IMAGE_OMITTED]';
                $data[$key.'_present'] = true;
            }
        }

        if (isset($data['data']) && is_array($data['data'])) {
            $data['data'] = $this->stripLargeBinaryFields($data['data']);
        }

        return $data;
    }
}
