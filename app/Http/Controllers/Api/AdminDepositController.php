<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Models\Deposit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminDepositController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $q = Deposit::query()->with('user')->orderByDesc('id');

        if ($request->filled('user_id')) {
            $q->where('user_id', (int) $request->query('user_id'));
        }
        if ($request->filled('status')) {
            $q->where('status', $request->query('status'));
        }
        if ($request->filled('from')) {
            $q->where('created_at', '>=', $request->query('from'));
        }
        if ($request->filled('to')) {
            $q->where('created_at', '<=', $request->query('to'));
        }

        return ResponseHelper::success($q->paginate($perPage), 'Deposits retrieved.');
    }

    public function show(int $id): JsonResponse
    {
        $deposit = Deposit::query()->with('user', 'bankAccount')->findOrFail($id);

        return ResponseHelper::success($deposit, 'Deposit retrieved.');
    }
}
