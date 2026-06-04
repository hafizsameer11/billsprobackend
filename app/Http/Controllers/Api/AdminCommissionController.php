<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Models\BillCommissionRate;
use App\Models\CommissionVolumeTier;
use App\Services\Admin\AdminAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminCommissionController extends Controller
{
    public function __construct(
        protected AdminAuditService $audit,
    ) {}

    public function tiers(): JsonResponse
    {
        $rows = CommissionVolumeTier::query()->orderBy('sort_order')->get();

        return ResponseHelper::success($rows, 'Commission volume tiers.');
    }

    public function updateTier(Request $request, string $tierKey): JsonResponse
    {
        $tier = CommissionVolumeTier::query()->where('tier_key', $tierKey)->firstOrFail();
        $data = $request->validate([
            'label' => ['sometimes', 'string', 'max:64'],
            'min_monthly_volume_ngn' => ['sometimes', 'numeric', 'min:0'],
            'max_monthly_volume_ngn' => ['nullable', 'numeric', 'min:0'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);
        $tier->update($data);

        return ResponseHelper::success($tier->fresh(), 'Tier updated.');
    }

    public function rates(Request $request): JsonResponse
    {
        $q = BillCommissionRate::query()->orderBy('scene')->orderBy('entity_key')->orderBy('tier_key');
        if ($request->filled('scene')) {
            $q->where('scene', (string) $request->query('scene'));
        }

        return ResponseHelper::success($q->get(), 'Commission rates.');
    }

    public function storeRate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scene' => ['required', Rule::in(['airtime', 'data', 'betting'])],
            'entity_key' => ['required', 'string', 'max:64'],
            'tier_key' => ['required', 'string', 'max:16'],
            'commission_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $row = BillCommissionRate::query()->updateOrCreate(
            [
                'scene' => $data['scene'],
                'entity_key' => $data['entity_key'],
                'tier_key' => $data['tier_key'],
            ],
            [
                'commission_pct' => $data['commission_pct'],
                'is_active' => $data['is_active'] ?? true,
            ]
        );

        return ResponseHelper::success($row, 'Commission rate saved.');
    }

    public function updateRate(Request $request, int $id): JsonResponse
    {
        $row = BillCommissionRate::query()->findOrFail($id);
        $data = $request->validate([
            'commission_pct' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $row->update($data);

        return ResponseHelper::success($row->fresh(), 'Commission rate updated.');
    }

    public function destroyRate(int $id): JsonResponse
    {
        BillCommissionRate::query()->where('id', $id)->delete();

        return ResponseHelper::success(null, 'Commission rate deleted.');
    }
}
