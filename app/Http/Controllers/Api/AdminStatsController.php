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
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminStatsController extends Controller
{
    /** @var array<string, true> */
    private const VALID_RANGES = ['7d' => true, '30d' => true, '90d' => true, '12m' => true];

    public function index(Request $request): JsonResponse
    {
        $rangeRaw = $request->query('range');
        $applyRange = is_string($rangeRaw) && $rangeRaw !== '' && isset(self::VALID_RANGES[$rangeRaw]);
        $range = $applyRange ? $rangeRaw : '12m';

        $periodStart = $applyRange ? $this->periodStart($range) : null;

        $pendingKyc = Kyc::query()->where('status', 'pending')->count();
        $approvedKyc = Kyc::query()->where('status', 'approved')->count();
        $rejectedKyc = Kyc::query()->where('status', 'rejected')->count();
        $usersWithoutKyc = User::query()->whereDoesntHave('kyc')->count();

        $openTickets = SupportTicket::query()->whereIn('status', ['open', 'in_progress'])->count();
        $usersTotal = User::query()->count();
        $newUsers30d = User::query()->where('created_at', '>=', now()->subDays(30))->count();
        $activeUsers = User::query()->where('account_status', 'active')->count();

        $transactionsQ = Transaction::query();
        if ($periodStart !== null) {
            $transactionsQ->where('created_at', '>=', $periodStart);
        }
        $transactionsTotal = (int) $transactionsQ->count();

        $recentVolume = (float) (Transaction::query()
            ->where('created_at', '>=', now()->subDays(7))
            ->where('status', 'completed')
            ->sum('amount') ?? 0);

        $revenueQ = Transaction::query()
            ->where('status', 'completed')
            ->where('currency', 'NGN');
        if ($periodStart !== null) {
            $revenueQ->where('created_at', '>=', $periodStart);
        }
        $revenueNgn = (float) ($revenueQ->sum('amount') ?? 0);

        $failedJobs = 0;
        if (DB::getSchemaBuilder()->hasTable('failed_jobs')) {
            $failedJobs = (int) DB::table('failed_jobs')->count();
        }

        $totalCards = VirtualCard::query()->count();
        $totalVcBalance = (float) VirtualCard::query()->sum('balance');
        $usersWithCards = (int) DB::table('virtual_cards')->selectRaw('count(distinct user_id) as cnt')->value('cnt');

        $totalNairaSystem = (float) FiatWallet::query()->where('currency', 'NGN')->sum('balance');

        $chartRange = $applyRange ? $range : '12m';
        [$chartLabels, $withdrawalSeries, $depositSeries] = $this->buildNgnWithdrawalDepositChart($chartRange);

        $latestUsers = User::query()
            ->orderByDesc('id')
            ->limit(10)
            ->get(['id', 'name', 'first_name', 'last_name', 'email', 'account_status', 'kyc_completed', 'created_at']);

        $payload = [
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
        ];

        if ($applyRange) {
            $payload['range'] = $range;
        }

        return ResponseHelper::success($payload, 'Stats retrieved.');
    }

    private function periodStart(string $range): CarbonInterface
    {
        return match ($range) {
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            '90d' => now()->subDays(90),
            '12m' => now()->subMonths(12),
            default => now()->subMonths(12),
        };
    }

    /**
     * @return array{0: list<string>, 1: list<float>, 2: list<float>}
     */
    private function buildNgnWithdrawalDepositChart(string $range): array
    {
        $chartLabels = [];
        $withdrawalSeries = [];
        $depositSeries = [];

        if ($range === '12m') {
            for ($i = 11; $i >= 0; $i--) {
                $d = Carbon::now()->subMonths($i);
                $chartLabels[] = $d->format('M');
                $withdrawalSeries[] = $this->sumNgnCompletedByMonth($d, 'withdrawal');
                $depositSeries[] = $this->sumNgnCompletedByMonth($d, 'deposit');
            }

            return [$chartLabels, $withdrawalSeries, $depositSeries];
        }

        if ($range === '7d') {
            for ($i = 6; $i >= 0; $i--) {
                $d = Carbon::now()->subDays($i)->startOfDay();
                $chartLabels[] = $d->format('D');
                $withdrawalSeries[] = $this->sumNgnCompletedOnDay($d, 'withdrawal');
                $depositSeries[] = $this->sumNgnCompletedOnDay($d, 'deposit');
            }

            return [$chartLabels, $withdrawalSeries, $depositSeries];
        }

        if ($range === '30d') {
            for ($i = 29; $i >= 0; $i--) {
                $d = Carbon::now()->subDays($i)->startOfDay();
                $chartLabels[] = $d->format('j M');
                $withdrawalSeries[] = $this->sumNgnCompletedOnDay($d, 'withdrawal');
                $depositSeries[] = $this->sumNgnCompletedOnDay($d, 'deposit');
            }

            return [$chartLabels, $withdrawalSeries, $depositSeries];
        }

        // 90d — weekly buckets (13 weeks)
        $start = Carbon::now()->subDays(90)->startOfDay();
        for ($w = 0; $w < 13; $w++) {
            $bucketStart = (clone $start)->addDays($w * 7);
            $bucketEnd = (clone $bucketStart)->addDays(6)->endOfDay();
            $chartLabels[] = $bucketStart->format('j M');
            $withdrawalSeries[] = $this->sumNgnCompletedBetween($bucketStart, $bucketEnd, 'withdrawal');
            $depositSeries[] = $this->sumNgnCompletedBetween($bucketStart, $bucketEnd, 'deposit');
        }

        return [$chartLabels, $withdrawalSeries, $depositSeries];
    }

    private function sumNgnCompletedByMonth(Carbon $monthRef, string $type): float
    {
        return (float) Transaction::query()
            ->where('status', 'completed')
            ->where('type', $type)
            ->where('currency', 'NGN')
            ->whereYear('created_at', $monthRef->year)
            ->whereMonth('created_at', $monthRef->month)
            ->sum('amount');
    }

    private function sumNgnCompletedOnDay(Carbon $day, string $type): float
    {
        return (float) Transaction::query()
            ->where('status', 'completed')
            ->where('type', $type)
            ->where('currency', 'NGN')
            ->whereDate('created_at', $day->toDateString())
            ->sum('amount');
    }

    private function sumNgnCompletedBetween(CarbonInterface $from, CarbonInterface $to, string $type): float
    {
        return (float) Transaction::query()
            ->where('status', 'completed')
            ->where('type', $type)
            ->where('currency', 'NGN')
            ->whereBetween('created_at', [$from, $to])
            ->sum('amount');
    }
}
