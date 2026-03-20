<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminWithdrawalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $q = Transaction::query()
            ->where('type', 'withdrawal')
            ->with('user')
            ->orderByDesc('id');

        if ($request->filled('user_id')) {
            $q->where('user_id', (int) $request->query('user_id'));
        }
        if ($request->filled('status')) {
            $q->where('status', $request->query('status'));
        }
        if ($request->filled('currency')) {
            $q->where('currency', $request->query('currency'));
        }
        if ($request->filled('from')) {
            $q->where('created_at', '>=', $request->query('from'));
        }
        if ($request->filled('to')) {
            $q->where('created_at', '<=', $request->query('to'));
        }

        return ResponseHelper::success($q->paginate($perPage), 'Withdrawals retrieved.');
    }

    public function show(string $transactionId): JsonResponse
    {
        $tx = Transaction::query()
            ->where('type', 'withdrawal')
            ->where(function ($w) use ($transactionId) {
                $w->where('transaction_id', $transactionId)->orWhere('id', $transactionId);
            })
            ->firstOrFail();

        return ResponseHelper::success($tx->load('user'), 'Withdrawal retrieved.');
    }
}
