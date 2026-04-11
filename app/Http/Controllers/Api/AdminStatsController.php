<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Models\Deposit;
use App\Models\FiatWallet;
use App\Models\Kyc;
use App\Models\SupportTicket;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VirtualCard;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AdminStatsController extends Controller
{
    public function index(): JsonResponse
    {
        $pendingKyc = Kyc::query()->where('status', 'pending')->count();
        $approvedKyc = Kyc::query()->where('status', 'approved')->count();
        $rejectedKyc = Kyc::query()->where('status', 'rejected')->count();
        $usersWithoutKyc = User::query()->whereDoesntHave('kyc')->count();

        $openTickets = SupportTicket::query()->whereIn('status', ['open', 'in_progress'])->count();
        $usersTotal = User::query()->count();
        $newUsers30d = User::query()->where('created_at', '>=', now()->subDays(30))->count();
        $activeUsers = User::query()->where('account_status', 'active')->count();

        $transactionsTotal = Transaction::query()->count();

        $recentVolume = (float) (Transaction::query()
            ->where('created_at', '>=', now()->subDays(7))
            ->where('status', 'completed')
            ->sum('amount') ?? 0);

        $revenueNgn = (float) (Transaction::query()
            ->where('status', 'completed')
            ->where('currency', 'NGN')
            ->sum('amount') ?? 0);

        $failedJobs = 0;
        if (DB::getSchemaBuilder()->hasTable('failed_jobs')) {
            $failedJobs = (int) DB::table('failed_jobs')->count();
        }

        $totalCards = VirtualCard::query()->count();
        $totalVcBalance = (float) VirtualCard::query()->sum('balance');
        $usersWithCards = (int) DB::table('virtual_cards')->selectRaw('count(distinct user_id) as cnt')->value('cnt');

        $totalNairaSystem = (float) FiatWallet::query()->where('currency', 'NGN')->sum('balance');

        $chartLabels = [];
        $withdrawalSeries = [];
        $depositSeries = [];
        for ($i = 11; $i >= 0; $i--) {
            $d = Carbon::now()->subMonths($i);
            $chartLabels[] = $d->format('M');
            $withdrawalSeries[] = (float) Transaction::query()
                ->where('status', 'completed')
                ->where('type', 'withdrawal')
                ->where('currency', 'NGN')
                ->whereYear('created_at', $d->year)
                ->whereMonth('created_at', $d->month)
                ->sum('amount');
            $depositSeries[] = (float) Transaction::query()
                ->where('status', 'completed')
                ->where('type', 'deposit')
                ->where('currency', 'NGN')
                ->whereYear('created_at', $d->year)
                ->whereMonth('created_at', $d->month)
                ->sum('amount');
        }

        $latestUsers = User::query()
            ->orderByDesc('id')
            ->limit(10)
            ->get(['id', 'name', 'first_name', 'last_name', 'email', 'account_status', 'kyc_completed', 'created_at']);

        return ResponseHelper::success([
            'users_total' => $usersTotal,
            'new_users_30d' => $newUsers30d,
            'active_users' => $activeUsers,
            'pending_kyc' => $pendingKyc,
            'kyc_approved' => $approvedKyc,
            'kyc_rejected' => $rejectedKyc,
            'users_without_kyc' => $usersWithoutKyc,
            'open_support_tickets' => $openTickets,
            'failed_jobs' => $failedJobs,
            'recent_transaction_volume_7d' => $recentVolume,
            'deposits_pending' => Deposit::query()->where('status', 'pending')->count(),
            'transactions_total' => $transactionsTotal,
            'revenue_ngn' => $revenueNgn,
            'revenue_ngn_display' => '₦'.number_format($revenueNgn, 0, '.', ','),
            'virtual_cards' => [
                'users_with_cards' => $usersWithCards,
                'total_cards' => $totalCards,
                'total_balance_display' => '$'.number_format($totalVcBalance, 2, '.', ','),
            ],
            'total_naira_in_wallets' => $totalNairaSystem,
            'total_naira_in_wallets_display' => '₦'.number_format($totalNairaSystem, 0, '.', ','),
            'chart' => [
                'labels' => $chartLabels,
                'withdrawals_ngn' => $withdrawalSeries,
                'deposits_ngn' => $depositSeries,
            ],
            'latest_users' => $latestUsers->map(function (User $u) {
                $name = $u->name ?: trim(($u->first_name ?? '').' '.($u->last_name ?? '')) ?: 'User #'.$u->id;

                return [
                    'id' => $u->id,
                    'display_name' => $name,
                    'email' => $u->email,
                    'account_status' => $u->account_status,
                    'kyc_completed' => (bool) $u->kyc_completed,
                    'created_at' => $u->created_at?->toIso8601String(),
                ];
            }),
        ], 'Stats retrieved.');
    }
}
