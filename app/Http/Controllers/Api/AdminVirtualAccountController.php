<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\VirtualAccount;
use App\Services\Admin\AdminAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminVirtualAccountController extends Controller
{
    public function __construct(
        protected AdminAuditService $audit
    ) {}

    public function forUser(Request $request, User $user): JsonResponse
    {
        $accounts = VirtualAccount::query()
            ->where('user_id', $user->id)
            ->with('walletCurrency')
            ->orderBy('id')
            ->get();

        return ResponseHelper::success($accounts, 'Virtual accounts retrieved.');
    }

    public function update(Request $request, VirtualAccount $virtualAccount): JsonResponse
    {
        $data = $request->validate([
            'frozen' => 'sometimes|boolean',
            'active' => 'sometimes|boolean',
        ]);

        $before = $virtualAccount->only(['frozen', 'active']);
        $virtualAccount->update($data);
        $this->audit->log((int) $request->user()->id, 'virtual_account.update', $virtualAccount, [
            'before' => $before,
            'after' => $virtualAccount->only(['frozen', 'active']),
        ], $request);

        return ResponseHelper::success($virtualAccount->fresh()->load('walletCurrency'), 'Virtual account updated.');
    }
}
