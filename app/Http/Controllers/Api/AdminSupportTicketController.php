<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Services\Admin\AdminAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminSupportTicketController extends Controller
{
    public function __construct(
        protected AdminAuditService $audit
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $q = SupportTicket::query()->with('user')->orderByDesc('id');

        if ($request->filled('status')) {
            $q->where('status', $request->query('status'));
        }
        if ($request->filled('user_id')) {
            $q->where('user_id', (int) $request->query('user_id'));
        }

        return ResponseHelper::success($q->paginate($perPage), 'Tickets retrieved.');
    }

    public function update(Request $request, SupportTicket $supportTicket): JsonResponse
    {
        $data = $request->validate([
            'status' => 'sometimes|string|max:50',
            'priority' => 'sometimes|string|max:50',
            'assigned_to' => 'nullable|integer|exists:users,id',
        ]);

        $before = $supportTicket->only(array_keys($data));
        $supportTicket->update($data);
        $this->audit->log((int) $request->user()->id, 'support_ticket.update', $supportTicket, [
            'before' => $before,
            'after' => $supportTicket->only(array_keys($data)),
        ], $request);

        return ResponseHelper::success($supportTicket->fresh()->load('user'), 'Ticket updated.');
    }
}
