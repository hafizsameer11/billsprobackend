<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Models\Deposit;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Admin\AdminUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminUserController extends Controller
{
    public function __construct(
        protected AdminUserService $adminUsers
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $filters = [
            'search' => $request->query('search'),
            'account_status' => $request->query('account_status'),
            'is_admin' => $request->query('is_admin'),
        ];

        return ResponseHelper::success(
            $this->adminUsers->paginateUsers($perPage, $filters),
            'Users retrieved.'
        );
    }

    public function show(User $user): JsonResponse
    {
        $user->makeVisible(['internal_notes']);

        return ResponseHelper::success($user, 'User retrieved.');
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'internal_notes' => 'nullable|string|max:65000',
            'suspension_reason' => 'nullable|string|max:2000',
        ]);

        if (array_key_exists('internal_notes', $data)) {
            $this->adminUsers->updateInternalNotes($user, (int) $request->user()->id, $data['internal_notes'], $request);
        }
        if (array_key_exists('suspension_reason', $data)) {
            $user->update(['suspension_reason' => $data['suspension_reason']]);
        }

        $user->refresh();
        $user->makeVisible(['internal_notes']);

        return ResponseHelper::success($user, 'User updated.');
    }

    public function suspend(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'reason' => 'nullable|string|max:2000',
        ]);

        $u = $this->adminUsers->suspend($user, (int) $request->user()->id, $data['reason'] ?? null, $request);
        $u->makeVisible(['internal_notes']);

        return ResponseHelper::success($u, 'User suspended.');
    }

    public function unsuspend(Request $request, User $user): JsonResponse
    {
        $u = $this->adminUsers->activate($user, (int) $request->user()->id, $request);
        $u->makeVisible(['internal_notes']);

        return ResponseHelper::success($u, 'User reactivated.');
    }

    public function ban(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'reason' => 'nullable|string|max:2000',
        ]);

        $u = $this->adminUsers->ban($user, (int) $request->user()->id, $data['reason'] ?? null, $request);
        $u->makeVisible(['internal_notes']);

        return ResponseHelper::success($u, 'User banned.');
    }

    public function revokeTokens(Request $request, User $user): JsonResponse
    {
        $this->adminUsers->revokeAllTokens($user, (int) $request->user()->id, $request);

        return ResponseHelper::success(null, 'All tokens revoked.');
    }

    public function timeline(Request $request, User $user): JsonResponse
    {
        $limit = min(100, max(1, (int) $request->query('limit', 50)));

        return ResponseHelper::success([
            'transactions' => Transaction::query()
                ->where('user_id', $user->id)
                ->orderByDesc('id')
                ->limit($limit)
                ->get(),
            'deposits' => Deposit::query()
                ->where('user_id', $user->id)
                ->orderByDesc('id')
                ->limit($limit)
                ->get(),
        ], 'Timeline retrieved.');
    }
}
