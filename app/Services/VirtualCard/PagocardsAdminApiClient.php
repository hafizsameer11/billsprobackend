<?php

namespace App\Services\VirtualCard;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class PagocardsAdminApiClient
{
    /**
     * @return array{status?: string, total?: int, transactions: list<array<string, mixed>>, visa_wallet_balance?: float|null, master_wallet_balance?: float|null}
     */
    public function listTransactions(int $perPage = 100, int $page = 1): array
    {
        $response = $this->get('/admin/transactions', [
            'per_page' => $perPage,
            'page' => $page,
        ]);

        $transactions = $response['transactions'] ?? [];
        if (! is_array($transactions)) {
            $transactions = [];
        }

        return array_merge($response, ['transactions' => array_values($transactions)]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findDeclineFeeDebitsSince(int $afterId, ?string $cardId = null): array
    {
        $all = $this->listTransactions(100);
        $matches = [];

        foreach ($all['transactions'] as $tx) {
            if (! is_array($tx)) {
                continue;
            }
            $id = (int) ($tx['id'] ?? 0);
            if ($id <= $afterId) {
                continue;
            }
            if (! $this->isVisaDeclineFeeDebit($tx)) {
                continue;
            }
            if ($cardId !== null && $cardId !== '') {
                $parsed = $this->parseDeclineFeeCardId((string) ($tx['description'] ?? ''));
                if ($parsed !== $cardId) {
                    continue;
                }
            }
            $matches[] = $tx;
        }

        usort($matches, static fn (array $a, array $b): int => ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0)));

        return $matches;
    }

    /**
     * @return array{visa_wallet_balance: float|null, master_wallet_balance: float|null}
     */
    public function getWalletBalances(): array
    {
        $response = $this->listTransactions(1);

        return [
            'visa_wallet_balance' => isset($response['visa_wallet_balance']) ? (float) $response['visa_wallet_balance'] : null,
            'master_wallet_balance' => isset($response['master_wallet_balance']) ? (float) $response['master_wallet_balance'] : null,
        ];
    }

    public function parseDeclineFeeCardId(string $description): ?string
    {
        if (preg_match('/decline fee for cardId:\s*([a-f0-9-]+)/i', $description, $m)) {
            return strtolower($m[1]);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $tx
     */
    public function isVisaDeclineFeeDebit(array $tx): bool
    {
        if (strtolower((string) ($tx['type'] ?? '')) !== 'debit') {
            return false;
        }
        if (strtolower((string) ($tx['wallet_type'] ?? '')) !== 'visa') {
            return false;
        }

        return stripos((string) ($tx['description'] ?? ''), 'decline fee for cardid:') !== false;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    protected function get(string $path, array $query = []): array
    {
        $baseUrl = rtrim((string) config('mastercard.merchant_base_url'), '/');
        $url = $baseUrl.'/'.ltrim($path, '/');

        try {
            $response = Http::timeout((int) config('mastercard.timeout', 30))
                ->acceptJson()
                ->withHeaders([
                    'publickey' => (string) config('mastercard.public_key'),
                    'secretkey' => (string) config('mastercard.secret_key'),
                ])
                ->get($url, $query);
        } catch (ConnectionException $e) {
            throw new MastercardApiException('Unable to connect to Pagocards admin API.', 503, [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
        }

        $data = $response->json();
        if (! is_array($data)) {
            $data = [];
        }

        if (! $response->ok()) {
            throw new MastercardApiException(
                (string) ($data['message'] ?? 'Pagocards admin API request failed.'),
                $response->status(),
                ['url' => $url, 'response' => $data]
            );
        }

        return $data;
    }
}
