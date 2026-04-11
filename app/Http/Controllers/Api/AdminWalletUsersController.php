<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Paginated user rows with aggregate wallet balances for the admin wallet management UI.
 */
class AdminWalletUsersController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $search = trim((string) $request->query('search', ''));

        $q = User::query()
            ->withSum([
                'fiatWallets as naira_balance' => function ($w) {
                    $w->where('currency', 'NGN');
                },
            ], 'balance')
            ->withSum(['virtualCards as virtual_card_balance_usd'], 'balance')
            ->withCount([
                'transactions as naira_tx_count' => function ($t) {
                    $t->where('currency', 'NGN');
                },
            ])
            ->withCount([
                'transactions as crypto_tx_count' => function ($t) {
                    $t->where(function ($x) {
                        $x->where('currency', '!=', 'NGN')
                            ->orWhereIn('type', ['crypto_deposit', 'crypto_buy', 'crypto_sell', 'crypto_withdrawal']);
                    });
                },
            ])
            ->orderByDesc('id');

        if ($search !== '') {
            $like = '%'.$search.'%';
            $q->where(function ($w) use ($like) {
                $w->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('phone_number', 'like', $like);
            });
        }

        $paginator = $q->paginate($perPage);

        $paginator->getCollection()->transform(function (User $u) {
            $naira = (float) ($u->naira_balance ?? 0);
            $cryptoUsd = (float) ($u->virtual_card_balance_usd ?? 0);

            return [
                'id' => $u->id,
                'display_name' => $u->name ?: trim(($u->first_name ?? '').' '.($u->last_name ?? '')) ?: 'User #'.$u->id,
                'email' => $u->email,
                'naira_balance_display' => '₦'.number_format($naira, 0, '.', ','),
                'crypto_balance_display' => '$'.number_format($cryptoUsd, 2, '.', ','),
                'naira_tx_count' => (int) ($u->naira_tx_count ?? 0),
                'crypto_tx_count' => (int) ($u->crypto_tx_count ?? 0),
            ];
        });

        return ResponseHelper::success($paginator, 'Wallet users retrieved.');
    }

    public function totals(): JsonResponse
    {
        $totalNaira = (float) \App\Models\FiatWallet::query()->where('currency', 'NGN')->sum('balance');
        $totalCryptoUsd = (float) \App\Models\VirtualCard::query()->sum('balance');

        return ResponseHelper::success([
            'total_naira_display' => '₦'.number_format($totalNaira, 0, '.', ','),
            'total_crypto_usd_display' => '$'.number_format($totalCryptoUsd, 2, '.', ','),
        ], 'Wallet totals retrieved.');
    }
}
