<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\VirtualCard\FundCardRequest;
use App\Models\User;
use App\Models\VirtualCard;
use App\Models\VirtualCardTransaction;
use App\Services\Admin\AdminAuditService;
use App\Services\VirtualCard\VirtualCardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminVirtualCardController extends Controller
{
    public function __construct(
        protected VirtualCardService $virtualCardService,
        protected AdminAuditService $audit
    ) {}

    /**
     * Virtual cards for a specific user (admin dashboard).
     *
     * Query: status = all | active | frozen
     */
    public function forUser(Request $request, User $user): JsonResponse
    {
        $status = (string) $request->query('status', 'all');
        $q = VirtualCard::query()->where('user_id', $user->id)->orderBy('id');

        if ($status === 'active') {
            $q->where('is_frozen', false);
        } elseif ($status === 'frozen') {
            $q->where('is_frozen', true);
        }

        $cards = $q->get()->map(fn (VirtualCard $c) => $this->formatAdminCardSummary($c, (int) $user->id));

        return ResponseHelper::success(['cards' => $cards], 'Virtual cards for user retrieved.');
    }

    /**
     * Paginated virtual card ledger rows for a user (admin dashboard).
     *
     * Query: category = all | deposits | withdrawals | payments
     *        tx_status = all | successful | pending | failed
     *        virtual_card_id = optional int
     *        search = optional (reference / id)
     *        date_from, date_to = optional YYYY-MM-DD (created_at)
     */
    public function transactionsForUser(Request $request, User $user): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));

        $query = VirtualCardTransaction::query()
            ->where('user_id', $user->id)
            ->with(['virtualCard:id,user_id,card_name,card_number'])
            ->orderByDesc('id');

        $category = (string) $request->query('category', 'all');
        if ($category === 'deposits') {
            $query->where('type', 'fund');
        } elseif ($category === 'withdrawals') {
            $query->where('type', 'withdraw');
        } elseif ($category === 'payments') {
            $query->whereIn('type', ['payment', 'refund']);
        }

        $txStatus = (string) $request->query('tx_status', 'all');
        if ($txStatus === 'successful') {
            $query->where('status', 'completed');
        } elseif ($txStatus === 'pending') {
            $query->where('status', 'pending');
        } elseif ($txStatus === 'failed') {
            $query->whereIn('status', ['failed', 'cancelled']);
        }

        if ($request->filled('virtual_card_id')) {
            $query->where('virtual_card_id', (int) $request->query('virtual_card_id'));
        }

        if ($request->filled('search')) {
            $term = trim((string) $request->query('search'));
            if ($term !== '') {
                $query->where(function ($q) use ($term) {
                    $q->where('reference', 'like', '%'.$term.'%');
                    if (ctype_digit($term)) {
                        $q->orWhere('id', (int) $term);
                    }
                });
            }
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', (string) $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', (string) $request->query('date_to'));
        }

        $paginator = $query->paginate($perPage);
        $paginator->getCollection()->transform(function (VirtualCardTransaction $t) use ($user) {
            return $this->formatAdminVirtualCardTransaction($t, (int) $user->id);
        });

        return ResponseHelper::success($paginator, 'Virtual card transactions retrieved.');
    }

    /**
     * Dashboard hero stats: distinct users with cards, total cards, aggregate USD balance.
     */
    public function summary(): JsonResponse
    {
        $totalCards = VirtualCard::query()->count();
        $totalBalance = (float) VirtualCard::query()->sum('balance');
        $usersWithCards = (int) VirtualCard::query()
            ->selectRaw('count(distinct user_id) as c')
            ->value('c');

        return ResponseHelper::success([
            'users_with_cards' => $usersWithCards,
            'total_cards' => $totalCards,
            'total_balance_display' => '$'.number_format($totalBalance, 2, '.', ','),
        ], 'Virtual card summary retrieved.');
    }

    /**
     * One row per user with aggregated card counts / balance (for Virtual Cards admin table).
     *
     * Query: status = all | active | frozen (filters which cards are included in aggregates)
     *        search — name, email, phone
     */
    public function usersOverview(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $status = (string) $request->query('status', 'all');
        $search = trim((string) $request->query('search', ''));
        $from = (string) $request->query('from', '');
        $to = (string) $request->query('to', '');

        $aggSub = VirtualCard::query()
            ->when($status === 'active', fn ($q) => $q->where('is_frozen', false))
            ->when($status === 'frozen', fn ($q) => $q->where('is_frozen', true))
            ->selectRaw('user_id')
            ->selectRaw('COUNT(*) as card_count')
            ->selectRaw('COALESCE(SUM(CAST(balance AS DECIMAL(20,8))), 0) as total_balance')
            ->groupBy('user_id');

        $lastTxSub = VirtualCardTransaction::query()
            ->selectRaw('user_id, MAX(created_at) as last_tx_at')
            ->groupBy('user_id');

        $query = User::query()
            ->joinSub($aggSub, 'agg', 'users.id', '=', 'agg.user_id')
            ->leftJoinSub($lastTxSub, 'ltx', 'users.id', '=', 'ltx.user_id')
            ->select([
                'users.id',
                'users.name',
                'users.first_name',
                'users.last_name',
                'users.email',
                'users.phone_number',
            ])
            ->addSelect([
                'agg.card_count',
                'agg.total_balance',
                'ltx.last_tx_at',
            ])
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.$search.'%';
                $q->where(function ($q2) use ($like) {
                    $q2->where('users.name', 'like', $like)
                        ->orWhere('users.email', 'like', $like)
                        ->orWhere('users.first_name', 'like', $like)
                        ->orWhere('users.last_name', 'like', $like)
                        ->orWhere('users.phone_number', 'like', $like);
                });
            })
            ->when($from !== '', function ($q) use ($from) {
                $q->whereDate('users.created_at', '>=', $from);
            })
            ->when($to !== '', function ($q) use ($to) {
                $q->whereDate('users.created_at', '<=', $to);
            })
            ->orderByDesc(DB::raw('agg.total_balance'));

        $paginator = $query->paginate($perPage);
        $paginator->getCollection()->transform(function ($row) {
            $displayName = (string) ($row->name ?? '');
            if ($displayName === '') {
                $displayName = trim(($row->first_name ?? '').' '.($row->last_name ?? '')) ?: 'User #'.$row->id;
            }
            $balance = (float) $row->total_balance;
            $lastTx = $row->last_tx_at;

            return [
                'user_id' => (int) $row->id,
                'display_name' => $displayName,
                'email' => $row->email,
                'phone_number' => $row->phone_number,
                'avatar_url' => null,
                'card_count' => (int) $row->card_count,
                'total_balance_display' => '$'.number_format($balance, 2, '.', ','),
                'last_tx_display' => $lastTx
                    ? \Carbon\Carbon::parse($lastTx)->timezone(config('app.timezone'))->format('m/d/y - h:i A')
                    : null,
            ];
        });

        return ResponseHelper::success($paginator, 'Virtual card users retrieved.');
    }

    public function unfreeze(Request $request, int $id): JsonResponse
    {
        $card = VirtualCard::query()->findOrFail($id);
        $result = $this->virtualCardService->toggleFreeze((int) $card->user_id, $id, false);
        if (! ($result['success'] ?? false)) {
            return ResponseHelper::error($result['message'] ?? 'Unfreeze failed', (int) ($result['status'] ?? 400));
        }

        $this->audit->log((int) $request->user()->id, 'virtual_card.unfreeze', $card, [], $request);

        return ResponseHelper::success($result['data'] ?? null, $result['message'] ?? 'Card unfrozen.');
    }

    public function fund(FundCardRequest $request, int $id): JsonResponse
    {
        $card = VirtualCard::query()->findOrFail($id);
        $result = $this->virtualCardService->fundCard((int) $card->user_id, $id, $request->validated());
        if (! ($result['success'] ?? false)) {
            return ResponseHelper::error($result['message'] ?? 'Card funding failed', (int) ($result['status'] ?? 400));
        }

        $this->audit->log((int) $request->user()->id, 'virtual_card.fund', $card, [
            'amount' => $request->input('amount'),
            'payment_wallet_type' => $request->input('payment_wallet_type'),
            'payment_wallet_currency' => $request->input('payment_wallet_currency'),
        ], $request);

        return ResponseHelper::success($result['data'] ?? null, $result['message'] ?? 'Card funded.');
    }

    private function formatAdminCardSummary(VirtualCard $card, int $userId): array
    {
        $label = $this->cardOrdinalLabel($card, $userId);
        $color = (string) ($card->card_color ?? 'green');
        $variant = match ($color) {
            'brown' => 'orange',
            'purple' => 'pink',
            default => 'green',
        };
        $lastFour = $card->card_number ? substr((string) $card->card_number, -4) : '0000';
        $balance = (float) $card->balance;

        return [
            'id' => $card->id,
            'user_id' => $card->user_id,
            'title' => 'Online Payment Virtual Card - '.$label,
            'short_name' => $label,
            'balance_display' => '$'.number_format($balance, 2, '.', ','),
            'last_four' => $lastFour,
            'status' => $card->is_frozen ? 'frozen' : 'active',
            'details_button_variant' => $variant,
            'is_frozen' => (bool) $card->is_frozen,
            'is_active' => (bool) $card->is_active,
            'card_color' => $color,
        ];
    }

    private function cardOrdinalLabel(VirtualCard $card, int $userId): string
    {
        $ids = VirtualCard::query()->where('user_id', $userId)->orderBy('id')->pluck('id');
        $pos = $ids->search($card->id);

        return 'Card '.(($pos !== false ? (int) $pos : 0) + 1);
    }

    private function formatAdminVirtualCardTransaction(VirtualCardTransaction $t, int $userId): array
    {
        $card = $t->virtualCard;
        $cardLabel = $card ? $this->cardOrdinalLabel($card, $userId) : 'Card';

        $publicId = $t->reference !== null && $t->reference !== ''
            ? (string) $t->reference
            : 'VCT-'.$t->id;

        return [
            'id' => $publicId,
            'database_id' => $t->id,
            'virtual_card_id' => $t->virtual_card_id,
            'amount' => $this->formatVirtualCardTxAmount($t),
            'status' => $this->mapVcTxUiStatus((string) $t->status),
            'card_label' => $cardLabel,
            'sub_type' => $this->mapVcTxSubType((string) $t->type),
            'date' => $t->created_at?->timezone(config('app.timezone'))->format('m/d/y - h:i A') ?? '',
            'kind' => $this->mapVcTxKind((string) $t->type),
            'raw_type' => $t->type,
            'raw_status' => $t->status,
        ];
    }

    private function formatVirtualCardTxAmount(VirtualCardTransaction $t): string
    {
        $currency = strtoupper((string) $t->currency);
        $total = (float) $t->total_amount;

        if ($currency === 'USD') {
            return '$'.number_format($total, 2, '.', ',');
        }

        return $currency.' '.number_format($total, 2, '.', ',');
    }

    private function mapVcTxUiStatus(string $status): string
    {
        return match (strtolower($status)) {
            'completed' => 'Successful',
            'pending' => 'Pending',
            'failed', 'cancelled' => 'Failed',
            default => ucfirst($status),
        };
    }

    private function mapVcTxSubType(string $type): string
    {
        return match ($type) {
            'fund' => 'Deposit',
            'withdraw' => 'Withdrawal',
            'payment' => 'Payment',
            'refund' => 'Refund',
            default => ucfirst($type),
        };
    }

    private function mapVcTxKind(string $type): string
    {
        return match ($type) {
            'fund' => 'deposit',
            'withdraw' => 'withdrawal',
            'payment', 'refund' => 'payment',
            default => 'payment',
        };
    }

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
