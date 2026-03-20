<?php

namespace App\Services\Crypto;

use App\Models\CryptoDepositAddress;
use App\Models\CryptoSweepOrder;
use App\Models\CryptoVendor;
use App\Models\MasterWalletTransaction;
use App\Models\VirtualAccount;
use App\Services\Tatum\DepositAddressService;
use App\Services\Tatum\TatumOutboundTxService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CryptoTreasuryService
{
    public function __construct(
        protected TatumOutboundTxService $tatumOutbound
    ) {}

    /**
     * Aggregate virtual-account balances by asset + recent deposit stats.
     *
     * @return array<string, mixed>
     */
    public function receivedSummary(): array
    {
        $balances = VirtualAccount::query()
            ->where('active', true)
            ->selectRaw('blockchain, currency, SUM(CAST(available_balance AS DECIMAL(24,8))) as total_available, COUNT(*) as accounts')
            ->groupBy('blockchain', 'currency')
            ->orderBy('currency')
            ->get();

        $depositTotals = Transaction::query()
            ->where('type', 'crypto_deposit')
            ->where('status', 'completed')
            ->selectRaw('currency, COALESCE(SUM(CAST(amount AS DECIMAL(24,8))), 0) as total_amount, COUNT(*) as tx_count')
            ->groupBy('currency')
            ->get();

        return [
            'virtual_balances_by_asset' => $balances,
            'completed_deposits_by_currency' => $depositTotals,
        ];
    }

    /**
     * Paginated on-chain credits (from Tatum webhook processing).
     */
    public function paginateDeposits(int $perPage = 25): LengthAwarePaginator
    {
        return Transaction::query()
            ->where('type', 'crypto_deposit')
            ->with(['user:id,name,email,phone_number'])
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * @return Collection<int, CryptoVendor>
     */
    public function listVendors(bool $activeOnly = true): Collection
    {
        $q = CryptoVendor::query()->orderBy('name');
        if ($activeOnly) {
            $q->where('is_active', true);
        }

        return $q->get();
    }

    public function createVendor(array $data): CryptoVendor
    {
        return CryptoVendor::create([
            'name' => $data['name'],
            'code' => strtolower($data['code']),
            'blockchain' => $data['blockchain'],
            'currency' => strtoupper($data['currency']),
            'payout_address' => $data['payout_address'],
            'contract_address' => $data['contract_address'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'metadata' => $data['metadata'] ?? null,
        ]);
    }

    public function updateVendor(CryptoVendor $vendor, array $data): CryptoVendor
    {
        $vendor->update(array_filter([
            'name' => $data['name'] ?? null,
            'code' => isset($data['code']) ? strtolower((string) $data['code']) : null,
            'blockchain' => $data['blockchain'] ?? null,
            'currency' => isset($data['currency']) ? strtoupper((string) $data['currency']) : null,
            'payout_address' => $data['payout_address'] ?? null,
            'contract_address' => $data['contract_address'] ?? null,
            'is_active' => $data['is_active'] ?? null,
            'metadata' => $data['metadata'] ?? null,
        ], fn ($v) => $v !== null));

        return $vendor->fresh();
    }

    /**
     * Record a sweep: move funds from user deposit address → vendor (on-chain step is async / manual until wired).
     */
    public function createSweepOrder(
        int $adminUserId,
        int $vendorId,
        int $virtualAccountId,
        string $amount
    ): CryptoSweepOrder {
        $vendor = CryptoVendor::query()->where('is_active', true)->findOrFail($vendorId);

        $account = VirtualAccount::query()
            ->where('id', $virtualAccountId)
            ->where('active', true)
            ->firstOrFail();

        if (strtoupper($account->currency) !== $vendor->currency) {
            throw new RuntimeException('Vendor currency does not match virtual account currency.');
        }

        if ($account->blockchain !== $vendor->blockchain) {
            throw new RuntimeException('Vendor blockchain does not match virtual account blockchain.');
        }

        $amt = (float) $amount;
        if ($amt <= 0) {
            throw new RuntimeException('Amount must be positive.');
        }

        $available = (float) ($account->available_balance ?? '0');
        if ($available < $amt) {
            throw new RuntimeException('Insufficient virtual account balance for sweep.');
        }

        $depositAddr = CryptoDepositAddress::query()
            ->where('virtual_account_id', $account->id)
            ->where('blockchain', $account->blockchain)
            ->where('currency', $account->currency)
            ->first();

        if (! $depositAddr) {
            throw new RuntimeException('No deposit address found for this virtual account; cannot record sweep source.');
        }

        return DB::transaction(function () use ($adminUserId, $vendor, $account, $amt, $depositAddr) {
            $order = CryptoSweepOrder::create([
                'crypto_vendor_id' => $vendor->id,
                'virtual_account_id' => $account->id,
                'user_id' => $account->user_id,
                'admin_user_id' => $adminUserId,
                'blockchain' => $account->blockchain,
                'currency' => $account->currency,
                'amount' => (string) $amt,
                'from_address' => $depositAddr->address,
                'to_address' => $vendor->payout_address,
                'status' => 'pending',
                'metadata' => [
                    'note' => 'On-chain broadcast not run yet — implement Tatum sweep or attach tx_hash manually.',
                ],
            ]);

            return $order;
        });
    }

    /**
     * @return LengthAwarePaginator<CryptoSweepOrder>
     */
    public function paginateSweeps(int $perPage = 25): LengthAwarePaginator
    {
        return CryptoSweepOrder::query()
            ->with(['vendor', 'virtualAccount', 'user:id,name,email', 'admin:id,name,email'])
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function markSweepTxHash(CryptoSweepOrder $order, string $txHash): CryptoSweepOrder
    {
        $order->update([
            'tx_hash' => $txHash,
            'status' => 'completed',
            'metadata' => array_merge($order->metadata ?? [], ['completed_by' => 'admin_tx_hash']),
        ]);

        return $order->fresh();
    }

    /**
     * Broadcast sweep from user deposit address → vendor payout address (Tatum).
     */
    public function executeSweepOnChain(int $orderId, int $adminUserId): CryptoSweepOrder
    {
        if (config('tatum.use_mock')) {
            throw new RuntimeException('Cannot execute on-chain sweep while TATUM_USE_MOCK is true.');
        }

        $order = CryptoSweepOrder::query()->with('vendor')->findOrFail($orderId);
        if ($order->status !== 'pending') {
            throw new RuntimeException('Sweep order is not pending.');
        }

        $deposit = CryptoDepositAddress::query()
            ->where('virtual_account_id', $order->virtual_account_id)
            ->where('blockchain', $order->blockchain)
            ->where('currency', $order->currency)
            ->firstOrFail();

        $vendor = $order->vendor;
        if (! $vendor) {
            throw new RuntimeException('Vendor missing for sweep order.');
        }

        $normalized = DepositAddressService::normalizeBlockchain((string) $order->blockchain);

        try {
            $broadcast = $this->tatumOutbound->sendFromDepositAddress(
                $deposit,
                $vendor->payout_address,
                (string) $order->amount,
                (string) $order->currency,
                $normalized
            );
        } catch (\Throwable $e) {
            $order->update([
                'status' => 'failed',
                'failure_reason' => $e->getMessage(),
                'metadata' => array_merge($order->metadata ?? [], ['execute_error' => $e->getMessage()]),
            ]);

            throw $e;
        }

        $order->update([
            'status' => 'completed',
            'tx_hash' => $broadcast['txId'],
            'metadata' => array_merge($order->metadata ?? [], [
                'executed_at' => now()->toIso8601String(),
                'executed_by_admin_id' => $adminUserId,
            ]),
        ]);

        MasterWalletTransaction::create([
            'master_wallet_id' => null,
            'user_id' => $order->user_id,
            'type' => 'flush',
            'blockchain' => $normalized,
            'currency' => strtoupper((string) $order->currency),
            'from_address' => $deposit->address,
            'to_address' => $vendor->payout_address,
            'amount' => $order->amount,
            'network_fee' => $broadcast['fee'] ?? null,
            'tx_hash' => $broadcast['txId'],
            'internal_transaction_id' => null,
            'crypto_sweep_order_id' => $order->id,
            'metadata' => [
                'admin_user_id' => $adminUserId,
                'tatum' => $broadcast['raw'] ?? [],
            ],
        ]);

        return $order->fresh();
    }

    /**
     * @return LengthAwarePaginator<MasterWalletTransaction>
     */
    public function paginateExternalSends(int $perPage = 25): LengthAwarePaginator
    {
        return MasterWalletTransaction::query()
            ->with(['user:id,name,email', 'masterWallet', 'sweepOrder'])
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }
}
