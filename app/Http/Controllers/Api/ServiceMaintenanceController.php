<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Services\Platform\ServiceMaintenanceService;
use Illuminate\Http\JsonResponse;

class ServiceMaintenanceController extends Controller
{
    public function __construct(
        protected ServiceMaintenanceService $maintenanceService,
    ) {}

    public function index(): JsonResponse
    {
        $this->maintenanceService->syncCatalog();

        return ResponseHelper::success([
            'items' => $this->maintenanceService->activeForPublic(),
        ], 'Active service maintenance flags retrieved.');
    }
}
