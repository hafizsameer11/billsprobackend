<?php

namespace App\Services\Crypto;

use App\Models\CryptoDepositAddress;
use App\Models\CryptoSweepOrder;
use App\Models\CryptoVendor;
use App\Models\MasterWallet;
use App\Models\MasterWalletTransaction;
use App\Models\Transaction;
use App\Models\VirtualAccount;
use App\Services\Tatum\DepositAddressService;
use App\Services\Tatum\TatumOutboundTxService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
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
    public function paginateDeposits(int $perPage, ?Request $request = null): LengthAwarePaginator
    {
        $q = Transaction::query()
            ->where('type', 'crypto_deposit')
            ->with(['user:id,name,email,phone_number']);

        if ($request) {
            if ($request->filled('user_id')) {
                $q->where('user_id', (int) $request->query('user_id'));
            }
            if ($request->filled('currency')) {
                $q->where('currency', strtoupper((string) $request->query('currency')));
            }
            if ($request->filled('blockchain')) {
                $b = (string) $request->query('blockchain');
                $q->where(function ($w) use ($b) {
                    $w->where('metadata->blockchain', $b)
                        ->orWhere('metadata->network', $b);
                });
            }
            if ($request->filled('tx_hash')) {
                $h = (string) $request->query('tx_hash');
                $q->where('metadata->tx_hash', 'like', '%'.$h.'%');
            }
            if ($request->filled('date_from')) {
                $q->where('created_at', '>=', $request->query('date_from'));
            }
            if ($request->filled('date_to')) {
                $q->where('created_at', '<=', $request->query('date_to'));
            }
        }

        return $q->orderByDesc('created_at')
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
     * @param  'vendor'|'master'  $sweepTarget
     */
    public function createSweepOrder(
        int $adminUserId,
        int $virtualAccountId,
        string $amount,
        string $sweepTarget,
        ?int $vendorId = null
    ): CryptoSweepOrder {
        $account = VirtualAccount::query()
            ->where('id', $virtualAccountId)
            ->where('active', true)
            ->firstOrFail();

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

        $normalized = DepositAddressService::normalizeBlockchain((string) $account->blockchain);

        if ($sweepTarget === 'vendor') {
            if ($vendorId === null) {
                throw new RuntimeException('vendor_id is required for vendor sweep.');
            }
            $vendor = CryptoVendor::query()->where('is_active', true)->findOrFail($vendorId);

            if (strtoupper($account->currency) !== $vendor->currency) {
                throw new RuntimeException('Vendor currency does not match virtual account currency.');
            }

            if ($account->blockchain !== $vendor->blockchain) {
                throw new RuntimeException('Vendor blockchain does not match virtual account blockchain.');
            }

            return DB::transaction(function () use ($adminUserId, $vendor, $account, $amt, $depositAddr) {
                return CryptoSweepOrder::create([
                    'crypto_vendor_id' => $vendor->id,
                    'sweep_target' => 'vendor',
                    'virtual_account_id' => $account->id,
                    'user_id' => $account->user_id,
                    'admin_user_id' => $adminUserId,
                    'blockchain' => $account->blockchain,
                    'currency' => $account->currency,
                    'amount' => (string) $amt,
                    'from_address' => $depositAddr->address,
                    'to_address' => $vendor->payout_address,
                    'master_wallet_id' => null,
                    'status' => 'pending',
                    'metadata' => [
                        'note' => 'Pending on-chain execution via POST .../sweeps/{id}/execute',
                    ],
                ]);
            });
        }

        $master = MasterWallet::query()
            ->where('blockchain', $normalized)
            ->first();
        if (! $master || empty($master->address)) {
            throw new RuntimeException("No master wallet configured for blockchain \"{$normalized}\".");
        }

        return DB::transaction(function () use ($adminUserId, $account, $amt, $depositAddr, $master) {
            return CryptoSweepOrder::create([
                'crypto_vendor_id' => null,
                'sweep_target' => 'master',
                'virtual_account_id' => $account->id,
                'user_id' => $account->user_id,
                'admin_user_id' => $adminUserId,
                'blockchain' => $account->blockchain,
                'currency' => $account->currency,
                'amount' => (string) $amt,
                'from_address' => $depositAddr->address,
                'to_address' => $master->address,
                'master_wallet_id' => $master->id,
                'status' => 'pending',
                'metadata' => [
                    'note' => 'Pending on-chain sweep to master wallet — execute via POST .../sweeps/{id}/execute',
                ],
            ]);
        });
    }

    /**
     * @return LengthAwarePaginator<CryptoSweepOrder>
     */
    public function paginateSweeps(int $perPage = 25): LengthAwarePaginator
    {
        return CryptoSweepOrder::query()
            ->with(['vendor', 'virtualAccount', 'user:id,name,email', 'admin:id,name,email', 'masterWallet'])
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function markSweepTxHash(CryptoSweepOrder $order, string $txHash): CryptoSweepOrder
    {
        if ($order->status !== 'pending') {
            throw new RuntimeException('Sweep order is not pending.');
        }

        return DB::transaction(function () use ($order, $txHash) {
            $order->update([
                'tx_hash' => $txHash,
                'status' => 'completed',
                'metadata' => array_merge($order->metadata ?? [], ['completed_by' => 'admin_tx_hash']),
            ]);

            $account = VirtualAccount::query()
                ->whereKey($order->virtual_account_id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->debitVirtualAccountForSweep($account, (float) $order->amount);

            return $order->fresh();
        });
    }

    /**
     * Broadcast sweep from user deposit address (Tatum).
     */
    public function executeSweepOnChain(int $orderId, int $adminUserId): CryptoSweepOrder
    {
        if (config('tatum.use_mock')) {
            throw new RuntimeException('Cannot execute on-chain sweep while TATUM_USE_MOCK is true.');
        }

        $order = CryptoSweepOrder::query()->with(['vendor', 'masterWallet'])->findOrFail($orderId);
        if ($order->status !== 'pending') {
            throw new RuntimeException('Sweep order is not pending.');
        }

        $deposit = CryptoDepositAddress::query()
            ->where('virtual_account_id', $order->virtual_account_id)
            ->where('blockchain', $order->blockchain)
            ->where('currency', $order->currency)
            ->firstOrFail();

        $normalized = DepositAddressService::normalizeBlockchain((string) $order->blockchain);

        $toAddress = null;
        if ($order->sweep_target === 'master') {
            $master = $order->masterWallet ?? MasterWallet::query()->where('blockchain', $normalized)->first();
            if (! $master || empty($master->address)) {
                throw new RuntimeException('Master wallet missing for this sweep.');
            }
            $toAddress = $master->address;
        } else {
            $vendor = $order->vendor;
            if (! $vendor) {
                throw new RuntimeException('Vendor missing for sweep order.');
            }
            $toAddress = $vendor->payout_address;
        }

        try {
            $broadcast = $this->tatumOutbound->sendFromDepositAddress(
                $deposit,
                $toAddress,
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

        return DB::transaction(function () use ($order, $broadcast, $toAddress, $deposit, $normalized, $adminUserId) {
            $order->refresh();
            $account = VirtualAccount::query()
                ->whereKey($order->virtual_account_id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->debitVirtualAccountForSweep($account, (float) $order->amount);

            $order->update([
                'status' => 'completed',
                'tx_hash' => $broadcast['txId'],
                'metadata' => array_merge($order->metadata ?? [], [
                    'executed_at' => now()->toIso8601String(),
                    'executed_by_admin_id' => $adminUserId,
                ]),
            ]);

            MasterWalletTransaction::create([
                'master_wallet_id' => $order->master_wallet_id,
                'user_id' => $order->user_id,
                'type' => 'flush',
                'blockchain' => $normalized,
                'currency' => strtoupper((string) $order->currency),
                'from_address' => $deposit->address,
                'to_address' => $toAddress,
                'amount' => $order->amount,
                'network_fee' => $broadcast['fee'] ?? null,
                'tx_hash' => $broadcast['txId'],
                'internal_transaction_id' => null,
                'crypto_sweep_order_id' => $order->id,
                'metadata' => [
                    'admin_user_id' => $adminUserId,
                    'sweep_target' => $order->sweep_target,
                    'tatum' => $broadcast['raw'] ?? [],
                ],
            ]);

            return $order->fresh();
        });
    }

    protected function debitVirtualAccountForSweep(VirtualAccount $account, float $amount): void
    {
        if ($amount <= 0) {
            return;
        }

        $available = (float) ($account->available_balance ?? '0');
        $ledger = (float) ($account->account_balance ?? '0');
        if ($available < $amount - 1e-12) {
            throw new RuntimeException('Insufficient virtual balance to finalize sweep ledger debit.');
        }

        $account->available_balance = (string) ($available - $amount);
        $account->account_balance = (string) ($ledger - $amount);
        $account->save();
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
