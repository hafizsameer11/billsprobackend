<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\User;
use App\Services\Admin\AdminAuditService;
use App\Services\Chat\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AdminSupportTicketController extends Controller
{
    public function __construct(
        protected AdminAuditService $audit
    ) {}

    public function summary(): JsonResponse
    {
        $total = SupportTicket::query()->count();
        $pending = SupportTicket::query()->where('status', 'open')->count();
        $ongoing = SupportTicket::query()->where('status', 'in_progress')->count();
        $resolved = SupportTicket::query()->whereIn('status', ['resolved', 'closed'])->count();

        return ResponseHelper::success([
            'total_chats' => $total,
            'pending_chat' => $pending,
            'ongoing_chat' => $ongoing,
            'resolved_chat' => $resolved,
        ], 'Support summary.');
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $q = SupportTicket::query()->with(['user:id,name,first_name,last_name,email', 'assignedAdmin:id,name,first_name,last_name,email'])->orderByDesc('id');

        if ($request->filled('user_id')) {
            $q->where('user_id', (int) $request->query('user_id'));
        }

        if ($request->filled('status')) {
            $st = (string) $request->query('status');
            if ($st === 'pending') {
                $q->where('status', 'open');
            } elseif ($st === 'ongoing') {
                $q->where('status', 'in_progress');
            } elseif ($st === 'resolved') {
                $q->whereIn('status', ['resolved', 'closed']);
            } elseif (in_array($st, ['open', 'in_progress', 'resolved', 'closed'], true)) {
                $q->where('status', $st);
            }
        }

        if ($request->filled('priority') && $request->query('priority') !== 'all') {
            $q->where('priority', (string) $request->query('priority'));
        }

        if ($request->filled('search')) {
            $s = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim((string) $request->query('search'))).'%';
            $q->where(function ($w) use ($s) {
                $w->where('ticket_number', 'like', $s)
                    ->orWhere('subject', 'like', $s)
                    ->orWhere('description', 'like', $s)
                    ->orWhereHas('user', function ($u) use ($s) {
                        $u->where('name', 'like', $s)
                            ->orWhere('email', 'like', $s)
                            ->orWhere('first_name', 'like', $s)
                            ->orWhere('last_name', 'like', $s);
                    });
            });
        }
        if ($request->filled('from')) {
            $q->whereDate('created_at', '>=', (string) $request->query('from'));
        }
        if ($request->filled('to')) {
            $q->whereDate('created_at', '<=', (string) $request->query('to'));
        }

        $paginator = $q->paginate($perPage);
        $paginator->getCollection()->transform(fn (SupportTicket $t) => $this->formatListRow($t));

        return ResponseHelper::success($paginator, 'Tickets retrieved.');
    }

    public function show(SupportTicket $supportTicket): JsonResponse
    {
        $supportTicket->load([
            'user:id,name,first_name,last_name,email,phone_number',
            'assignedAdmin:id,name,first_name,last_name,email',
            'messages' => fn ($q) => $q->orderBy('created_at')->with('author:id,name,first_name,last_name,email'),
        ]);

        $messages = $supportTicket->messages->map(fn (SupportTicketMessage $m) => $this->formatMessage($m));

        if ($messages->isEmpty() && $supportTicket->description) {
            $messages = collect([
                [
                    'id' => 0,
                    'sender_role' => SupportTicketMessage::ROLE_USER,
                    'body' => $supportTicket->description,
                    'created_at' => $supportTicket->created_at?->toIso8601String(),
                    'author_display' => $this->displayName($supportTicket->user),
                ],
            ]);
        }

        return ResponseHelper::success([
            'ticket' => $this->formatDetail($supportTicket),
            'messages' => $messages->values()->all(),
        ], 'Ticket detail.');
    }

    public function storeMessage(Request $request, SupportTicket $supportTicket): JsonResponse
    {
        $data = $request->validate([
            'body' => 'required|string|max:10000',
        ]);

        $msg = SupportTicketMessage::create([
            'support_ticket_id' => $supportTicket->id,
            'sender_role' => SupportTicketMessage::ROLE_ADMIN,
            'user_id' => (int) $request->user()->id,
            'body' => $data['body'],
        ]);

        if ($supportTicket->status === 'open') {
            $supportTicket->update(['status' => 'in_progress']);
        }

        if ($supportTicket->chat_session_id) {
            try {
                app(ChatService::class)->sendMessage(
                    (int) $supportTicket->chat_session_id,
                    (int) $request->user()->id,
                    $data['body'],
                    'admin'
                );
            } catch (\Throwable $e) {
                Log::warning('Admin reply could not be mirrored to in-app live chat: '.$e->getMessage());
            }
        }

        $this->audit->log((int) $request->user()->id, 'support_ticket.message', $supportTicket, [
            'message_id' => $msg->id,
        ], $request);

        $msg->load('author:id,name,first_name,last_name,email');

        return ResponseHelper::success([
            'message' => $this->formatMessage($msg),
            'ticket' => $this->formatDetail($supportTicket->fresh(['user', 'assignedAdmin'])),
        ], 'Message sent.', 201);
    }

    public function update(Request $request, SupportTicket $supportTicket): JsonResponse
    {
        $data = $request->validate([
            'status' => 'sometimes|string|max:50',
            'priority' => 'sometimes|string|max:50',
            'assigned_to' => 'nullable|integer|exists:users,id',
        ]);

        $before = $supportTicket->only(array_keys($data));
        $patch = $data;
        if (isset($data['status']) && in_array($data['status'], ['resolved', 'closed'], true)) {
            $patch['resolved_at'] = $supportTicket->resolved_at ?? now();
        }
        $supportTicket->update($patch);
        $this->audit->log((int) $request->user()->id, 'support_ticket.update', $supportTicket, [
            'before' => $before,
            'after' => $supportTicket->only(array_keys($data)),
        ], $request);

        return ResponseHelper::success($supportTicket->fresh()->load(['user', 'assignedAdmin']), 'Ticket updated.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatListRow(SupportTicket $t): array
    {
        $agent = $t->assignedAdmin;

        return [
            'id' => $t->id,
            'ticket_number' => $t->ticket_number,
            'subject' => $t->subject,
            'issue_type' => $t->issue_type,
            'status' => $t->status,
            'status_label' => $this->displayStatus($t->status),
            'priority' => $t->priority,
            'user' => $t->user ? [
                'id' => $t->user->id,
                'display_name' => $this->displayName($t->user),
                'email' => $t->user->email,
            ] : null,
            'agent_display' => $agent ? $this->displayName($agent) : '—',
            'created_at' => $t->created_at?->toIso8601String(),
            'updated_at' => $t->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatDetail(SupportTicket $t): array
    {
        $agent = $t->assignedAdmin;
        $user = $t->user;

        return [
            'id' => $t->id,
            'ticket_number' => $t->ticket_number,
            'subject' => $t->subject,
            'description' => $t->description,
            'issue_type' => $t->issue_type,
            'status' => $t->status,
            'status_label' => $this->displayStatus($t->status),
            'priority' => $t->priority,
            'resolved_at' => $t->resolved_at?->toIso8601String(),
            'user' => $user ? [
                'id' => $user->id,
                'display_name' => $this->displayName($user),
                'email' => $user->email,
            ] : null,
            'agent_display' => $agent ? $this->displayName($agent) : null,
            'created_at' => $t->created_at?->toIso8601String(),
        ];
    }

    protected function displayStatus(string $status): string
    {
        return match ($status) {
            'open' => 'Pending',
            'in_progress' => 'Ongoing',
            'resolved' => 'Resolved',
            'closed' => 'Resolved',
            default => ucfirst($status),
        };
    }

    protected function displayName(?User $u): string
    {
        if (! $u) {
            return '—';
        }
        $n = trim((string) $u->name);
        if ($n !== '') {
            return $n;
        }
        $fn = trim((string) ($u->first_name ?? ''));
        $ln = trim((string) ($u->last_name ?? ''));
        $combo = trim($fn.' '.$ln);
        if ($combo !== '') {
            return $combo;
        }

        return (string) ($u->email ?? 'User #'.$u->id);
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatMessage(SupportTicketMessage $m): array
    {
        $author = $m->author;

        return [
            'id' => $m->id,
            'sender_role' => $m->sender_role,
            'body' => $m->body,
            'created_at' => $m->created_at?->toIso8601String(),
            'author_display' => $author ? $this->displayName($author) : 'System',
        ];
    }
}
