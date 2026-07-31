<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Services\Admin\DaybookReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminDaybookController extends Controller
{
    public function __construct(
        protected DaybookReportService $daybook,
    ) {}

    /**
     * Everything that happened on one day: money in, money out, fees, cards, pending work.
     */
    public function day(Request $request): JsonResponse
    {
        $data = $this->daybook->day(
            $this->dateParam($request),
            $request->boolean('include_test'),
        );

        return ResponseHelper::success($data, 'Day book retrieved.');
    }

    /**
     * Paginated list of the day's transactions.
     */
    public function transactions(Request $request): JsonResponse
    {
        $paginator = $this->daybook->transactions(
            $this->dateParam($request),
            trim((string) $request->query('type', '')),
            trim((string) $request->query('status', '')),
            trim((string) $request->query('search', '')),
            $request->boolean('include_test'),
            (int) $request->query('per_page', 25),
        );

        return ResponseHelper::success($paginator, 'Day book transactions retrieved.');
    }

    private function dateParam(Request $request): ?string
    {
        $date = $request->query('date');

        return is_string($date) ? $date : null;
    }
}
