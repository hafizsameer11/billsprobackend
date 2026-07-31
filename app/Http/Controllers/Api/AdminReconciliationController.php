<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Admin\ReconciliationReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminReconciliationController extends Controller
{
    public function __construct(
        protected ReconciliationReportService $reconciliation,
    ) {}

    /**
     * Platform money-story overview for a date range.
     */
    public function overview(Request $request): JsonResponse
    {
        $from = $request->query('from');
        $to = $request->query('to');
        $data = $this->reconciliation->overview(
            is_string($from) ? $from : null,
            is_string($to) ? $to : null,
        );

        return ResponseHelper::success($data, 'Reconciliation overview retrieved.');
    }

    /**
     * Per-user rollup table.
     */
    public function users(Request $request): JsonResponse
    {
        $from = $request->query('from');
        $to = $request->query('to');
        $search = trim((string) $request->query('search', ''));
        $perPage = (int) $request->query('per_page', 25);

        $paginator = $this->reconciliation->usersOverview(
            is_string($from) ? $from : null,
            is_string($to) ? $to : null,
            $search,
            $perPage,
        );

        return ResponseHelper::success($paginator, 'Reconciliation users retrieved.');
    }

    /**
     * Full money story for one user.
     */
    public function userShow(Request $request, User $user): JsonResponse
    {
        $from = $request->query('from');
        $to = $request->query('to');
        $data = $this->reconciliation->userStory(
            $user,
            is_string($from) ? $from : null,
            is_string($to) ? $to : null,
        );

        return ResponseHelper::success($data, 'User money story retrieved.');
    }
}
