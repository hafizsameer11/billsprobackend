<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CryptoDepositAddress;
use App\Models\ReceivedAsset;
use App\Models\Transaction;
use App\Models\VirtualAccount;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Public backfill: create `received_assets` from an existing `crypto_deposit` transaction row.
 * Use once per legacy deposit when the row was created before `received_assets` existed.
 */
class CryptoReceivedAssetSyncController extends Controller
{
    public function syncFromTransaction(Request $request): JsonResponse
    {
        $transactionId = (int) $request->query('transaction_id', 0);
        if ($transactionId <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Query parameter transaction_id is required (integer primary key of transactions table).',
            ], 422);
        }

        $tx = Transaction::query()->where('type', 'crypto_deposit')->whereKey($transactionId)->first();
        if (! $tx) {
            return response()->json([
                'success' => false,
                'message' => 'No crypto_deposit transaction found for this id.',
            ], 404);
        }

        $existing = ReceivedAsset::query()->where('transaction_id', $tx->id)->first();
        if ($existing) {
            return response()->json([
                'success' => true,
                'message' => 'Already synced; no changes.',
                'received_asset_id' => $existing->id,
                'transaction_id' => $tx->id,
            ], 200);
        }

        $meta = is_array($tx->metadata) ? $tx->metadata : [];
        $vaId = isset($meta['virtual_account_id']) ? (int) $meta['virtual_account_id'] : 0;
        if ($vaId <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction metadata is missing virtual_account_id; cannot sync.',
            ], 422);
        }

        $va = VirtualAccount::query()->find($vaId);
        if (! $va) {
            return response()->json([
                'success' => false,
                'message' => 'Virtual account not found.',
            ], 422);
        }

        $txHash = isset($meta['tx_hash']) ? (string) $meta['tx_hash'] : '';
        if ($txHash === '') {
            return response()->json([
                'success' => false,
                'message' => 'Transaction metadata is missing tx_hash; cannot sync.',
            ], 422);
        }

        if ((int) $tx->user_id !== (int) $va->user_id) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction user_id does not match virtual account user_id.',
            ], 422);
        }

        $logIndex = (int) ($meta['received_asset_log_index'] ?? $meta['log_index'] ?? 0);
        $from = isset($meta['from_address']) ? (string) $meta['from_address'] : null;
        $to = isset($meta['to_address']) ? (string) $meta['to_address'] : null;
        $blockchain = isset($meta['blockchain']) ? (string) $meta['blockchain'] : (string) $va->blockchain;

        $depositAddr = null;
        if (is_string($to) && $to !== '') {
            $depositAddr = CryptoDepositAddress::query()
                ->where('virtual_account_id', $va->id)
                ->whereRaw('LOWER(TRIM(address)) = ?', [strtolower(trim($to))])
                ->first();
        }
        if (! $depositAddr) {
            $depositAddr = CryptoDepositAddress::query()
                ->where('virtual_account_id', $va->id)
                ->orderByDesc('id')
                ->first();
        }

        try {
            $row = DB::transaction(function () use ($tx, $va, $txHash, $logIndex, $from, $to, $blockchain, $depositAddr, $meta) {
                return ReceivedAsset::query()->create([
                    'user_id' => (int) $tx->user_id,
                    'virtual_account_id' => $va->id,
                    'transaction_id' => $tx->id,
                    'crypto_deposit_address_id' => $depositAddr?->id,
                    'blockchain' => $blockchain,
                    'currency' => strtoupper((string) $tx->currency),
                    'amount' => $tx->amount,
                    'tx_hash' => $txHash,
                    'log_index' => $logIndex,
                    'from_address' => $from,
                    'to_address' => $to,
                    'source' => 'sync_from_transaction',
                    'status' => 'received',
                    'metadata' => [
                        'synced_at' => now()->toIso8601String(),
                        'subscription_type' => $meta['subscription_type'] ?? null,
                        'network' => $meta['network'] ?? null,
                    ],
                ]);
            });
        } catch (QueryException $e) {
            $msg = strtolower($e->getMessage());
            if (str_contains($msg, 'unique') || str_contains($msg, 'duplicate')) {
                $byHash = ReceivedAsset::query()->where('tx_hash', $txHash)->where('log_index', $logIndex)->first();
                if ($byHash) {
                    return response()->json([
                        'success' => false,
                        'message' => 'A received_assets row already exists for this tx_hash + log_index. Link transaction manually if needed.',
                        'existing_received_asset_id' => $byHash->id,
                    ], 409);
                }
            }

            return response()->json([
                'success' => false,
                'message' => 'Could not create received_assets row: '.$e->getMessage(),
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Received asset row created from transaction.',
            'received_asset_id' => $row->id,
            'transaction_id' => $tx->id,
        ], 200);
    }
}
