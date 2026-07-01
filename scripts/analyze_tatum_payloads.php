<?php

/**
 * One-off analyzer for tatum_raw_webhooks.sql and webhook_raw_payloads.sql
 * Usage: php scripts/analyze_tatum_payloads.php
 */

function parseSqlPayloads(string $path): array
{
    $payloads = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return [];
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || ! str_starts_with($line, '(')) {
            continue;
        }

        $start = strpos($line, '{"');
        if ($start === false) {
            $start = strpos($line, '{\\"');
        }
        if ($start === false) {
            continue;
        }

        $depth = 0;
        $end = -1;
        $len = strlen($line);
        for ($i = $start; $i < $len; $i++) {
            $ch = $line[$i];
            if ($ch === '{' && ($i === $start || $line[$i - 1] !== '\\')) {
                $depth++;
            } elseif ($ch === '}' && $line[$i - 1] !== '\\') {
                $depth--;
                if ($depth === 0) {
                    $end = $i + 1;
                    break;
                }
            }
        }

        if ($end === -1) {
            continue;
        }

        $raw = str_replace('\\"', '"', substr($line, $start, $end - $start));
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $payloads[] = $decoded;
        }
    }

    return $payloads;
}

function normalizePayload(array $p): array
{
    if (isset($p['data']) && is_array($p['data'])) {
        $inner = $p['data'];
        foreach (['subscriptionType', 'type', 'chain', 'subscriptionId'] as $k) {
            if (! array_key_exists($k, $inner) && array_key_exists($k, $p)) {
                $inner[$k] = $p[$k];
            }
        }

        return $inner;
    }

    return $p;
}

function summarize(string $name, array $payloads): void
{
    echo "\n=== {$name} (".count($payloads)." payloads) ===\n";

    $normalized = array_map('normalizePayload', $payloads);

    $subTypes = [];
    $types = [];
    $currencies = [];
    $chains = [];
    $keys = [];
    $shapes = [];

    $fees = 0;
    $noSender = 0;
    $hasAccount = 0;
    $hasAsset = 0;
    $hasContract = 0;
    $hasTokenId = 0;
    $hasTo = 0;
    $addressOnly = 0;

    foreach ($payloads as $p) {
        foreach (array_keys($p) as $k) {
            $keys[$k] = ($keys[$k] ?? 0) + 1;
        }
        if (isset($p['data']) && is_array($p['data'])) {
            foreach (array_keys($p['data']) as $k) {
                $keys['data.'.$k] = ($keys['data.'.$k] ?? 0) + 1;
            }
        }
    }

    foreach ($normalized as $p) {
        $st = (string) ($p['subscriptionType'] ?? '?');
        $subTypes[$st] = ($subTypes[$st] ?? 0) + 1;

        $t = (string) ($p['type'] ?? '?');
        $types[$t] = ($types[$t] ?? 0) + 1;

        $cur = (string) ($p['currency'] ?? $p['asset'] ?? '?');
        $currencies[$cur] = ($currencies[$cur] ?? 0) + 1;

        $chain = (string) ($p['chain'] ?? '?');
        $chains[$chain] = ($chains[$chain] ?? 0) + 1;

        $typeLower = strtolower($t);
        $amount = (string) ($p['amount'] ?? '');
        if ($typeLower === 'fee' || str_starts_with($amount, '-')) {
            $fees++;
        }

        $sender = $p['counterAddress'] ?? $p['counteraddress'] ?? $p['from'] ?? $p['counter_address'] ?? null;
        if ($sender === null || $sender === '') {
            $noSender++;
        }

        if (! empty($p['accountId']) || ! empty($p['account_id'])) {
            $hasAccount++;
        }
        if (! empty($p['asset'])) {
            $hasAsset++;
        }
        if (! empty($p['contractAddress'])) {
            $hasContract++;
        }
        if (! empty($p['tokenId'])) {
            $hasTokenId++;
        }
        if (! empty($p['to'])) {
            $hasTo++;
        }
        if (! empty($p['address']) && empty($p['to'])) {
            $addressOnly++;
        }

        $addrKind = ! empty($p['to']) ? 'to' : (! empty($p['address']) ? 'addr' : 'noaddr');
        $assetKind = ! empty($p['asset']) ? 'asset' : (! empty($p['contractAddress']) ? 'contract' : 'currency');
        $shape = $st.'|'.$t.'|'.$addrKind.'|'.$assetKind;
        $shapes[$shape] = ($shapes[$shape] ?? 0) + 1;
    }

    arsort($subTypes);
    arsort($types);
    arsort($currencies);
    arsort($chains);
    arsort($keys);
    arsort($shapes);

    echo 'subscriptionTypes: '.json_encode($subTypes)."\n";
    echo 'type field: '.json_encode($types)."\n";
    echo 'currency/asset top: '.json_encode(array_slice($currencies, 0, 20, true))."\n";
    echo 'chain top: '.json_encode(array_slice($chains, 0, 15, true))."\n";
    echo 'top keys: '.json_encode(array_slice($keys, 0, 30, true))."\n";
    echo "fee/negative amount: {$fees}\n";
    echo "no sender: {$noSender}\n";
    echo "has accountId: {$hasAccount}\n";
    echo "has asset: {$hasAsset}\n";
    echo "has contractAddress: {$hasContract}\n";
    echo "has tokenId: {$hasTokenId}\n";
    echo "has to: {$hasTo}\n";
    echo "address without to: {$addressOnly}\n";
    echo "shape combos: ".count($shapes)."\n";
    foreach (array_slice($shapes, 0, 25, true) as $shape => $count) {
        echo "  {$count} {$shape}\n";
    }
}

$root = dirname(__DIR__, 2);
$tatum = parseSqlPayloads($root.'/tatum_raw_webhooks.sql');
$webhook = parseSqlPayloads($root.'/webhook_raw_payloads.sql');

summarize('tatum_raw_webhooks', $tatum);
summarize('webhook_raw_payloads', $webhook);

$all = array_map('normalizePayload', array_merge($tatum, $webhook));
$unsupported = [];
$depositLike = 0;
foreach ($all as $p) {
    $st = (string) ($p['subscriptionType'] ?? '');
    if ($st !== '' && ! in_array($st, [
        'ADDRESS_EVENT',
        'INCOMING_NATIVE_TX',
        'INCOMING_FUNGIBLE_TX',
        'ACCOUNT_INCOMING_BLOCKCHAIN_TRANSACTION',
    ], true)) {
        $unsupported[$st] = ($unsupported[$st] ?? 0) + 1;
    }

    $typeLower = strtolower((string) ($p['type'] ?? ''));
    $amount = (float) str_replace('-', '', (string) ($p['amount'] ?? '0'));
    if (! in_array($typeLower, ['fee', 'internal'], true)
        && in_array($st, [
            'ADDRESS_EVENT',
            'INCOMING_NATIVE_TX',
            'INCOMING_FUNGIBLE_TX',
            'ACCOUNT_INCOMING_BLOCKCHAIN_TRANSACTION',
        ], true)
        && $amount > 0) {
        $depositLike++;
    }
}

echo "\nUnsupported subscriptionTypes: ".json_encode($unsupported)."\n";
echo "Total positive deposit-like payloads: {$depositLike}\n";

function inferSubscriptionType(array $data): string
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

function wouldSkipNonDeposit(array $data, string $subscriptionType): bool
{
    $payloadType = strtolower((string) ($data['type'] ?? ''));
    if (in_array($payloadType, ['fee', 'internal', 'failed'], true)) {
        return true;
    }

    $amount = (string) ($data['amount'] ?? '');
    if ($amount !== '' && str_starts_with($amount, '-')) {
        return true;
    }

    return str_starts_with($subscriptionType, 'OUTGOING_');
}

function allowsMissingSender(string $subscriptionType): bool
{
    return in_array($subscriptionType, [
        'INCOMING_NATIVE_TX',
        'INCOMING_FUNGIBLE_TX',
        'ACCOUNT_INCOMING_BLOCKCHAIN_TRANSACTION',
    ], true);
}

function classifyPayload(array $data): string
{
    $data = normalizePayload($data);
    $subscriptionType = inferSubscriptionType($data);

    if (wouldSkipNonDeposit($data, $subscriptionType)) {
        return 'ignored_non_deposit';
    }

    $txId = (string) ($data['txId'] ?? $data['txHash'] ?? $data['hash'] ?? '');
    if ($txId === '') {
        return 'failed_missing_tx';
    }

    $depositTypes = [
        'ADDRESS_EVENT',
        'INCOMING_NATIVE_TX',
        'INCOMING_FUNGIBLE_TX',
        'ACCOUNT_INCOMING_BLOCKCHAIN_TRANSACTION',
    ];
    if (! in_array($subscriptionType, $depositTypes, true)) {
        return 'failed_unsupported_type:'.$subscriptionType;
    }

    $to = $data['to'] ?? $data['address'] ?? null;
    if (! is_string($to) || trim($to) === '') {
        return 'failed_missing_address';
    }

    $sender = $data['counterAddress'] ?? $data['counteraddress'] ?? $data['from'] ?? $data['counter_address'] ?? null;
    if ($sender === null || $sender === '') {
        if ($subscriptionType === 'ADDRESS_EVENT') {
            return 'ignored_no_sender';
        }
        if (! allowsMissingSender($subscriptionType)) {
            return 'ignored_no_sender';
        }
    }

    return 'would_process_deposit';
}

$outcomes = [];
$depositWouldProcess = 0;
$depositWouldSkip = 0;
foreach ($all as $p) {
    $outcome = classifyPayload($p);
    $outcomes[$outcome] = ($outcomes[$outcome] ?? 0) + 1;
    if ($outcome === 'would_process_deposit') {
        $depositWouldProcess++;
    } elseif (! str_starts_with($outcome, 'ignored_non_deposit') && ! str_starts_with($outcome, 'failed_unsupported_type')) {
        $st = inferSubscriptionType(normalizePayload($p));
        if (in_array($st, [
            'ADDRESS_EVENT',
            'INCOMING_NATIVE_TX',
            'INCOMING_FUNGIBLE_TX',
            'ACCOUNT_INCOMING_BLOCKCHAIN_TRANSACTION',
        ], true) && ! wouldSkipNonDeposit(normalizePayload($p), $st)) {
            $depositWouldSkip++;
        }
    }
}

echo "\n=== Handler classification (no DB) ===\n";
ksort($outcomes);
foreach ($outcomes as $outcome => $count) {
    echo "  {$count} {$outcome}\n";
}
echo "Deposit-shaped that would reach address lookup: {$depositWouldProcess}\n";
echo "Deposit-shaped skipped before address lookup: {$depositWouldSkip}\n";
