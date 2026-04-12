<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminTransactionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $q = Transaction::query()->with('user')->orderByDesc('id');

        if ($request->filled('user_id')) {
            $q->where('user_id', (int) $request->query('user_id'));
        }
        if ($request->filled('type')) {
            $q->where('type', $request->query('type'));
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
        if ($request->filled('search')) {
            $s = '%'.trim((string) $request->query('search')).'%';
            $q->where(function ($w) use ($s) {
                $w->where('transaction_id', 'like', $s)
                    ->orWhere('reference', 'like', $s)
                    ->orWhere('description', 'like', $s)
                    ->orWhereHas('user', function ($u) use ($s) {
                        $u->where('name', 'like', $s)
                            ->orWhere('email', 'like', $s)
                            ->orWhere('phone_number', 'like', $s);
                    });
            });
        }

        return ResponseHelper::success($q->paginate($perPage), 'Transactions retrieved.');
    }

    public function show(string $transactionId): JsonResponse
    {
        $tx = Transaction::query()
            ->where(function ($q) use ($transactionId) {
                $q->where('transaction_id', $transactionId)->orWhere('id', $transactionId);
            })
            ->firstOrFail();

        return ResponseHelper::success($tx->load('user'), 'Transaction retrieved.');
    }
}
