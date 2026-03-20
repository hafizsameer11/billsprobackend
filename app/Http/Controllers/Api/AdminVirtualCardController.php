<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Models\VirtualCard;
use App\Services\Admin\AdminAuditService;
use App\Services\VirtualCard\VirtualCardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminVirtualCardController extends Controller
{
    public function __construct(
        protected VirtualCardService $virtualCardService,
        protected AdminAuditService $audit
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $q = VirtualCard::query()->with('user')->orderByDesc('id');

        if ($request->filled('user_id')) {
            $q->where('user_id', (int) $request->query('user_id'));
        }

        return ResponseHelper::success($q->paginate($perPage), 'Virtual cards retrieved.');
    }

    public function show(int $id): JsonResponse
    {
        $card = VirtualCard::query()->with('user')->findOrFail($id);

        return ResponseHelper::success($card, 'Virtual card retrieved.');
    }

    public function freeze(Request $request, int $id): JsonResponse
    {
        $card = VirtualCard::query()->findOrFail($id);
        $result = $this->virtualCardService->toggleFreeze((int) $card->user_id, $id, true);
        if (! ($result['success'] ?? false)) {
            return ResponseHelper::error($result['message'] ?? 'Freeze failed', (int) ($result['status'] ?? 400));
        }

        $this->audit->log((int) $request->user()->id, 'virtual_card.freeze', $card, [], $request);

        return ResponseHelper::success($result['data'] ?? null, $result['message'] ?? 'Card frozen.');
    }
}
