<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Services\Platform\ServiceMaintenanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminServiceMaintenanceController extends Controller
{
    public function __construct(
        protected ServiceMaintenanceService $maintenanceService,
    ) {}

    public function index(): JsonResponse
    {
        $rows = $this->maintenanceService->listForAdmin();

        return ResponseHelper::success([
            'items' => $rows->map(fn ($row) => [
                'id' => $row->id,
                'slug' => $row->slug,
                'group' => $row->group,
                'label' => $row->label,
                'is_under_maintenance' => $row->is_under_maintenance,
                'notice_title' => $row->notice_title,
                'notice_message' => $row->notice_message,
                'alternate_hint' => $row->alternate_hint,
                'updated_at' => $row->updated_at?->toIso8601String(),
            ])->values()->all(),
        ], 'Service maintenance settings retrieved.');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'is_under_maintenance' => ['required', 'boolean'],
            'notice_title' => ['nullable', 'string', 'max:255'],
            'notice_message' => ['nullable', 'string', 'max:5000'],
            'alternate_hint' => ['nullable', 'string', 'max:500'],
        ]);

        $row = $this->maintenanceService->update($id, $data);

        return ResponseHelper::success([
            'id' => $row->id,
            'slug' => $row->slug,
            'group' => $row->group,
            'label' => $row->label,
            'is_under_maintenance' => $row->is_under_maintenance,
            'notice_title' => $row->notice_title,
            'notice_message' => $row->notice_message,
            'alternate_hint' => $row->alternate_hint,
            'updated_at' => $row->updated_at?->toIso8601String(),
        ], 'Service maintenance setting updated.');
    }
}
