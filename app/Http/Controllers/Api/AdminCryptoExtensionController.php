<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Models\CryptoDepositAddress;
use App\Models\MasterWallet;
use App\Models\User;
use App\Models\VirtualAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminCryptoExtensionController extends Controller
{
    public function userVirtualAccounts(User $user): JsonResponse
    {
        $accounts = VirtualAccount::query()
            ->where('user_id', $user->id)
            ->with('walletCurrency')
            ->orderBy('id')
            ->get();

        return ResponseHelper::success($accounts, 'Virtual accounts retrieved.');
    }

    public function userDepositAddresses(User $user): JsonResponse
    {
        $addresses = CryptoDepositAddress::query()
            ->whereHas('virtualAccount', fn ($q) => $q->where('user_id', $user->id))
            ->with('virtualAccount')
            ->orderBy('id')
            ->get();

        return ResponseHelper::success($addresses, 'Deposit addresses retrieved.');
    }

    public function depositAddresses(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $q = CryptoDepositAddress::query()
            ->with(['virtualAccount.user'])
            ->orderByDesc('id');

        if ($request->filled('user_id')) {
            $q->whereHas('virtualAccount', fn ($w) => $w->where('user_id', (int) $request->query('user_id')));
        }

        return ResponseHelper::success($q->paginate($perPage), 'Deposit addresses retrieved.');
    }

    public function masterWallets(): JsonResponse
    {
        $rows = MasterWallet::query()
            ->select(['id', 'blockchain', 'address', 'label', 'created_at', 'updated_at'])
            ->orderBy('id')
            ->get();

        return ResponseHelper::success($rows, 'Master wallets (public metadata only).');
    }
}
