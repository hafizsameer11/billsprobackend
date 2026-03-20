<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Models\Deposit;
use App\Models\Kyc;
use App\Models\SupportTicket;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AdminStatsController extends Controller
{
    public function index(): JsonResponse
    {
        $pendingKyc = Kyc::query()->where('status', 'pending')->count();
        $openTickets = SupportTicket::query()->whereIn('status', ['open', 'in_progress'])->count();
        $usersTotal = User::query()->count();

        $recentVolume = (float) (Transaction::query()
            ->where('created_at', '>=', now()->subDays(7))
            ->where('status', 'completed')
            ->sum('amount') ?? 0);

        $failedJobs = 0;
        if (DB::getSchemaBuilder()->hasTable('failed_jobs')) {
            $failedJobs = (int) DB::table('failed_jobs')->count();
        }

        return ResponseHelper::success([
            'users_total' => $usersTotal,
            'pending_kyc' => $pendingKyc,
            'open_support_tickets' => $openTickets,
            'failed_jobs' => $failedJobs,
            'recent_transaction_volume_7d' => $recentVolume,
            'deposits_pending' => Deposit::query()->where('status', 'pending')->count(),
        ], 'Stats retrieved.');
    }
}
