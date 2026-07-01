<?php

namespace App\Services\Tatum;

class TatumWebhookPayloadNormalizer
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function normalize(array $data): array
    {
        if (! isset($data['data']) || ! is_array($data['data'])) {
            return $data;
        }

        $inner = $data['data'];
        foreach (['subscriptionType', 'type', 'chain', 'subscriptionId'] as $key) {
            if (! array_key_exists($key, $inner) && array_key_exists($key, $data)) {
                $inner[$key] = $data[$key];
            }
        }

        return $inner;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function inferChannel(array $data): string
    {
        $subscriptionType = (string) ($data['subscriptionType'] ?? '');
        if ($subscriptionType === 'ACCOUNT_INCOMING_BLOCKCHAIN_TRANSACTION') {
            return 'v3_ledger';
        }
        if (isset($data['data']) && is_array($data['data'])) {
            return 'v4_wrapped';
        }
        if (isset($data['kind']) || isset($data['tokenMetadata'])) {
            return 'v4_enriched';
        }
        if ($subscriptionType === 'ADDRESS_EVENT') {
            return 'v4_address_event';
        }

        return 'v4';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function extractTxId(array $data): ?string
    {
        $txId = (string) ($data['txId'] ?? $data['txHash'] ?? $data['hash'] ?? '');

        return $txId !== '' ? $txId : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function extractReceivingAddress(array $data): ?string
    {
        foreach (['to', 'address'] as $key) {
            $value = $data[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function inferSubscriptionType(array $data): string
    {
        $subscriptionType = (string) ($data['subscriptionType'] ?? '');
        if ($subscriptionType !== '') {
            return $subscriptionType;
        }

        $kind = strtolower((string) ($data['kind'] ?? ''));
        if ($kind === 'token_transfer') {
            return 'INCOMING_FUNGIBLE_TX';
        }
        if (in_array($kind, ['native_transfer', 'native', 'transfer'], true)) {
            if (isset($data['tokenMetadata']['type']) && strtolower((string) $data['tokenMetadata']['type']) === 'fungible') {
                return 'INCOMING_FUNGIBLE_TX';
            }
            if (! empty($data['contractAddress']) || ! empty($data['tokenId'])) {
                return 'INCOMING_FUNGIBLE_TX';
            }

            return 'INCOMING_NATIVE_TX';
        }

        $payloadType = strtolower((string) ($data['type'] ?? ''));
        if (in_array($payloadType, ['token', 'native', 'fee'], true)) {
            return 'ADDRESS_EVENT';
        }

        if (! empty($data['contractAddress']) || ! empty($data['tokenId'])) {
            return 'INCOMING_FUNGIBLE_TX';
        }

        if (($data['amount'] ?? $data['value'] ?? '') !== '') {
            return 'INCOMING_NATIVE_TX';
        }

        return $payloadType;
    }
}
