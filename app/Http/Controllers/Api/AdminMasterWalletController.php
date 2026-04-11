<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Models\FiatWallet;
use App\Models\MasterWalletTransaction;
use App\Models\User;
use App\Models\VirtualAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminMasterWalletController extends Controller
{
    /**
     * Aggregate NGN across all user fiat wallets + estimated USD value of crypto virtual accounts.
     */
    public function summary(): JsonResponse
    {
        $ngnTotal = (float) FiatWallet::query()
            ->where('currency', 'NGN')
            ->selectRaw('COALESCE(SUM(CAST(balance AS DECIMAL(24,8))), 0) as t')
            ->value('t');

        $accounts = VirtualAccount::query()
            ->where('active', true)
            ->with('walletCurrency.exchangeRate')
            ->get();

        $cryptoUsd = 0.0;
        foreach ($accounts as $a) {
            $wc = $a->walletCurrency;
            if (! $wc) {
                continue;
            }
            $rate = $wc->usdPerUnitForDisplay();
            $bal = (float) ($a->available_balance ?? 0);
            $cryptoUsd += $bal * $rate;
        }

        return ResponseHelper::success([
            'total_naira_balance' => number_format($ngnTotal, 2, '.', ''),
            'total_crypto_usd_estimate' => number_format($cryptoUsd, 2, '.', ''),
            'virtual_accounts_count' => $accounts->count(),
        ], 'Master wallet summary.');
    }

    public function transactions(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $tab = (string) $request->query('tab', 'all');

        $q = MasterWalletTransaction::query()
            ->with(['user:id,name,first_name,last_name,email', 'masterWallet:id,blockchain,label,address'])
            ->orderByDesc('id');

        if ($request->filled('search')) {
            $s = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim((string) $request->query('search'))).'%';
            $q->where(function ($w) use ($s) {
                $w->where('tx_hash', 'like', $s)
                    ->orWhere('internal_transaction_id', 'like', $s)
                    ->orWhere('to_address', 'like', $s)
                    ->orWhere('currency', 'like', $s)
                    ->orWhere('blockchain', 'like', $s)
                    ->orWhereHas('user', function ($u) use ($s) {
                        $u->where('name', 'like', $s)->orWhere('email', 'like', $s);
                    });
            });
        }

        if ($tab === 'crypto') {
            // already crypto-only table
        } elseif ($tab === 'naira') {
            $q->whereRaw('1 = 0');
        }

        $paginator = $q->paginate($perPage);
        $paginator->getCollection()->transform(fn (MasterWalletTransaction $r) => $this->formatMwRow($r));

        return ResponseHelper::success($paginator, 'Master wallet transactions.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatMwRow(MasterWalletTransaction $r): array
    {
        $user = $r->user;
        $dest = $user ? $this->displayName($user) : null;
        if ($dest === null || $dest === '') {
            $dest = $r->to_address ? substr((string) $r->to_address, 0, 10).'…' : '—';
        }

        $mw = $r->masterWallet;

        return [
            'id' => $r->id,
            'wallet_name' => 'Crypto',
            'provider' => 'Tatum',
            'transaction_type' => $r->type,
            'destination' => $dest,
            'transaction_id' => $r->internal_transaction_id ?? (string) $r->id,
            'tx_hash' => $r->tx_hash,
            'amount' => (string) $r->amount,
            'network_fee' => $r->network_fee !== null ? (string) $r->network_fee : null,
            'currency' => $r->currency,
            'blockchain' => $r->blockchain,
            'status' => $r->tx_hash ? 'Successful' : 'Pending',
            'created_at' => $r->created_at?->toIso8601String(),
            'master_wallet_label' => $mw?->label ?? $mw?->blockchain,
        ];
    }

    protected function displayName(User $u): string
    {
        $n = trim((string) $u->name);
        if ($n !== '') {
            return $n;
        }
        $fn = trim((string) ($u->first_name ?? ''));
        $ln = trim((string) ($u->last_name ?? ''));
        $combo = trim($fn.' '.$ln);
        if ($combo !== '') {
            return $combo;
        }

        return (string) ($u->email ?? 'User #'.$u->id);
    }
}
