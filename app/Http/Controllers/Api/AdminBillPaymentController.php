<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Models\PalmPayBillOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminBillPaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $q = PalmPayBillOrder::query()->with('user')->orderByDesc('id');

        if ($request->filled('user_id')) {
            $q->where('user_id', (int) $request->query('user_id'));
        }
        if ($request->filled('status')) {
            $q->where('status', $request->query('status'));
        }

        return ResponseHelper::success($q->paginate($perPage), 'Bill orders retrieved.');
    }
}
