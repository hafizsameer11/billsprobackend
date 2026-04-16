<?php

namespace App\Services\Admin;

use App\Models\FiatWallet;
use App\Models\Transaction;
use App\Models\VirtualAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminBalanceAdjustmentService
{
    public function __construct(
        protected AdminAuditService $audit
    ) {}

    /**
     * @return array{success: bool, message?: string, data?: array<string, mixed>}
     */
    public function adjustFiat(
        int $adminUserId,
        int $fiatWalletId,
        string $direction,
        string $amount,
        string $reason,
        ?string $reference,
        ?Request $request = null
    ): array {
        $delta = (float) $amount;
        if ($delta <= 0) {
            return ['success' => false, 'message' => 'Amount must be positive.'];
        }

        $isDebit = in_array(strtolower($direction), ['debit', 'subtract'], true);

        return DB::transaction(function () use ($adminUserId, $fiatWalletId, $isDebit, $delta, $reason, $reference, $request) {
            /** @var FiatWallet|null $wallet */
            $wallet = FiatWallet::query()->whereKey($fiatWalletId)->lockForUpdate()->first();
            if (! $wallet) {
                return ['success' => false, 'message' => 'Fiat wallet not found.'];
            }

            $before = (string) $wallet->balance;
            $change = $isDebit ? -$delta : $delta;
            $afterFloat = (float) $before + $change;
            if ($afterFloat < 0) {
                return ['success' => false, 'message' => 'Resulting balance cannot be negative.'];
            }

            $wallet->balance = (string) $afterFloat;
            $wallet->save();

            $type = $isDebit ? 'admin_debit' : 'admin_credit';
            $tx = Transaction::create([
                'user_id' => $wallet->user_id,
                'transaction_id' => Transaction::generateTransactionId(),
                'type' => $type,
                'category' => 'admin_adjustment',
                'status' => 'completed',
                'currency' => $wallet->currency,
                'amount' => (string) $delta,
                'fee' => '0',
                'total_amount' => (string) $delta,
                'reference' => $reference ?? ('ADJ-FIAT-'.$wallet->id.'-'.time()),
                'description' => 'Admin fiat '.($isDebit ? 'debit' : 'credit'),
                'metadata' => [
                    'admin_user_id' => $adminUserId,
                    'reason' => $reason,
                    'fiat_wallet_id' => $wallet->id,
                    'before_balance' => $before,
                    'after_balance' => (string) $wallet->balance,
                ],
                'completed_at' => now(),
            ]);

            $this->audit->log($adminUserId, 'adjustment.fiat', $wallet, [
                'direction' => $isDebit ? 'debit' : 'credit',
                'amount' => (string) $delta,
                'reason' => $reason,
                'transaction_id' => $tx->transaction_id,
            ], $request);

            return [
                'success' => true,
                'data' => [
                    'fiat_wallet' => $wallet->fresh(),
                    'transaction' => $tx,
                ],
            ];
        });
    }

    /**
     * @return array{success: bool, message?: string, data?: array<string, mixed>}
     */
    public function adjustCrypto(
        int $adminUserId,
        int $virtualAccountId,
        string $direction,
        string $amount,
        string $reason,
        ?string $reference,
        ?Request $request = null
    ): array {
        $delta = (float) $amount;
        if ($delta <= 0) {
            return ['success' => false, 'message' => 'Amount must be positive.'];
        }

        $isDebit = in_array(strtolower($direction), ['debit', 'subtract'], true);

        return DB::transaction(function () use ($adminUserId, $virtualAccountId, $isDebit, $delta, $reason, $reference, $request) {
            /** @var VirtualAccount|null $va */
            $va = VirtualAccount::query()->whereKey($virtualAccountId)->lockForUpdate()->first();
            if (! $va) {
                return ['success' => false, 'message' => 'Virtual account not found.'];
            }

            $beforeAvail = (float) ($va->available_balance ?? 0);
            $beforeAcct = (float) ($va->account_balance ?? 0);
            $change = $isDebit ? -$delta : $delta;
            $afterAvail = $beforeAvail + $change;
            $afterAcct = $beforeAcct + $change;
            if ($afterAvail < 0 || $afterAcct < 0) {
                return ['success' => false, 'message' => 'Resulting balance cannot be negative.'];
            }

            $va->available_balance = (string) $afterAvail;
            $va->account_balance = (string) $afterAcct;
            $va->save();

            $type = $isDebit ? 'admin_debit' : 'admin_credit';
            $reasonLower = strtolower($reason);
            $isGasTopUp = ! $isDebit && str_contains($reasonLower, 'gas');
            $description = $isGasTopUp
                ? 'Platform gas fee top-up by Bills Pro — covers network fees for your wallet.'
                : 'Admin crypto '.($isDebit ? 'debit' : 'credit');
            $tx = Transaction::create([
                'user_id' => $va->user_id,
                'transaction_id' => Transaction::generateTransactionId(),
                'type' => $type,
                'category' => 'admin_adjustment',
                'status' => 'completed',
                'currency' => $va->currency,
                'amount' => (string) $delta,
                'fee' => '0',
                'total_amount' => (string) $delta,
                'reference' => $reference ?? ('ADJ-VA-'.$va->id.'-'.time()),
                'description' => $description,
                'metadata' => [
                    'admin_user_id' => $adminUserId,
                    'reason' => $reason,
                    'virtual_account_id' => $va->id,
                    'blockchain' => $va->blockchain,
                    'before_available_balance' => (string) $beforeAvail,
                    'after_available_balance' => (string) $afterAvail,
                    'is_gas_topup' => $isGasTopUp,
                ],
                'completed_at' => now(),
            ]);

            $this->audit->log($adminUserId, 'adjustment.crypto', $va, [
                'direction' => $isDebit ? 'debit' : 'credit',
                'amount' => (string) $delta,
                'reason' => $reason,
                'transaction_id' => $tx->transaction_id,
            ], $request);

            return [
                'success' => true,
                'data' => [
                    'virtual_account' => $va->fresh(),
                    'transaction' => $tx,
                ],
            ];
        });
    }
}
