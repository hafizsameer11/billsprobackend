<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Models\PalmPayBillOrder;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminBillPaymentController extends Controller
{
    public function summary(): JsonResponse
    {
        $base = Transaction::query()->where('type', 'bill_payment');

        $usersWithBillPayments = (int) DB::table('transactions')
            ->where('type', 'bill_payment')
            ->count(DB::raw('DISTINCT user_id'));
        $totalBillTransactions = (clone $base)->count();
        $revenue = (clone $base)
            ->where('status', 'completed')
            ->where('currency', 'NGN')
            ->selectRaw('COALESCE(SUM(CAST(total_amount AS DECIMAL(24,8))), 0) as t')
            ->value('t');

        return ResponseHelper::success([
            'users_with_bill_payments' => $usersWithBillPayments,
            'total_bill_transactions' => $totalBillTransactions,
            'total_revenue_ngn' => (string) $revenue,
        ], 'Bill payment summary.');
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));

        $q = Transaction::query()
            ->where('type', 'bill_payment')
            ->with(['user:id,name,first_name,last_name,email,phone_number'])
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $st = (string) $request->query('status');
            $map = [
                'successful' => 'completed',
                'pending' => 'pending',
                'failed' => 'failed',
            ];
            if (isset($map[$st])) {
                $q->where('status', $map[$st]);
            } elseif (in_array($st, ['completed', 'pending', 'failed'], true)) {
                $q->where('status', $st);
            }
        }

        if ($request->filled('bill_type')) {
            $q->where('category', (string) $request->query('bill_type'));
        }

        if ($request->filled('search')) {
            $s = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim((string) $request->query('search'))).'%';
            $q->where(function ($w) use ($s) {
                $w->where('transaction_id', 'like', $s)
                    ->orWhere('reference', 'like', $s)
                    ->orWhereHas('user', function ($u) use ($s) {
                        $u->where('name', 'like', $s)
                            ->orWhere('email', 'like', $s)
                            ->orWhere('phone_number', 'like', $s)
                            ->orWhere('first_name', 'like', $s)
                            ->orWhere('last_name', 'like', $s);
                    });
            });
        }

        $paginator = $q->paginate($perPage);

        $paginator->getCollection()->transform(fn (Transaction $t) => $this->formatListRow($t));

        return ResponseHelper::success($paginator, 'Bill payment transactions.');
    }

    /**
     * Single bill payment transaction for receipt / detail (numeric id or public transaction_id).
     */
    public function show(string $id): JsonResponse
    {
        $tx = Transaction::query()
            ->where('type', 'bill_payment')
            ->where(function ($q) use ($id) {
                if (ctype_digit($id)) {
                    $q->where('id', (int) $id);
                } else {
                    $q->where('transaction_id', $id);
                }
            })
            ->with(['user:id,name,first_name,last_name,email,phone_number'])
            ->firstOrFail();

        $order = PalmPayBillOrder::query()->where('transaction_id', $tx->id)->first();

        return ResponseHelper::success([
            'transaction' => $this->serializeTransaction($tx),
            'palm_pay_bill_order' => $order,
            'receipt' => $this->buildReceipt($tx, $order),
        ], 'Bill payment detail.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatListRow(Transaction $t): array
    {
        $user = $t->user;
        $meta = is_array($t->metadata) ? $t->metadata : [];

        return [
            'id' => $t->id,
            'transaction_id' => $t->transaction_id,
            'reference' => $t->reference,
            'amount' => (string) $t->amount,
            'fee' => (string) $t->fee,
            'total_amount' => (string) $t->total_amount,
            'currency' => $t->currency,
            'status' => $t->status,
            'status_label' => $this->statusLabel($t->status),
            'service_label' => $this->serviceLabel($t->category, $meta),
            'bill_category' => $t->category,
            'user' => $user ? [
                'id' => $user->id,
                'display_name' => $this->displayName($user),
                'email' => $user->email,
                'avatar_url' => null,
            ] : null,
            'created_at' => $t->created_at?->toIso8601String(),
            'updated_at' => $t->updated_at?->toIso8601String(),
        ];
    }

    protected function statusLabel(?string $status): string
    {
        return match ($status) {
            'completed' => 'Successful',
            'pending' => 'Pending',
            'failed' => 'Failed',
            default => ucfirst((string) $status),
        };
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    protected function serviceLabel(?string $category, array $meta): string
    {
        if (! empty($meta['categoryName'])) {
            return (string) $meta['categoryName'];
        }
        if (! empty($meta['providerName'])) {
            return (string) $meta['providerName'];
        }
        if ($category) {
            return ucfirst(str_replace('_', ' ', $category));
        }

        return 'Bill payment';
    }

    protected function displayName(User $u): string
    {
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
    protected function serializeTransaction(Transaction $t): array
    {
        return [
            'id' => $t->id,
            'user_id' => $t->user_id,
            'transaction_id' => $t->transaction_id,
            'type' => $t->type,
            'category' => $t->category,
            'status' => $t->status,
            'currency' => $t->currency,
            'amount' => (string) $t->amount,
            'fee' => (string) $t->fee,
            'total_amount' => (string) $t->total_amount,
            'reference' => $t->reference,
            'description' => $t->description,
            'metadata' => $t->metadata,
            'created_at' => $t->created_at?->toIso8601String(),
            'completed_at' => $t->completed_at?->toIso8601String(),
            'user' => $t->user ? [
                'id' => $t->user->id,
                'display_name' => $this->displayName($t->user),
                'email' => $t->user->email,
                'phone_number' => $t->user->phone_number,
            ] : null,
        ];
    }

    protected function buildReceipt(Transaction $tx, ?PalmPayBillOrder $order): array
    {
        $meta = is_array($tx->metadata) ? $tx->metadata : [];
        $phone = (string) ($meta['phoneNumber'] ?? $meta['rechargeAccount'] ?? $meta['accountNumber'] ?? '');

        $biller = (string) ($meta['providerName'] ?? $meta['billerId'] ?? $meta['providerCode'] ?? $order?->biller_id ?? '—');
        $typeLine = $this->serviceLabel($tx->category, $meta);
        $tl = strtolower($typeLine);
        if ($typeLine !== 'Bill payment' && ! str_contains($tl, 'recharge') && ! str_contains($tl, 'payment')) {
            $typeLine .= ' recharge';
        }

        return [
            'amount_display' => $this->moneyDisplay((float) $tx->amount, $tx->currency),
            'fee_display' => $this->moneyDisplay((float) $tx->fee, $tx->currency),
            'total_amount_display' => $this->moneyDisplay((float) $tx->total_amount, $tx->currency),
            'biller_type' => $biller,
            'phone_number' => $phone !== '' ? $phone : '—',
            'transaction_id' => $tx->transaction_id,
            'transaction_type' => $typeLine,
            'reference' => $tx->reference,
            'description' => $tx->description,
            'status_label' => $this->statusLabel($tx->status),
            'subtitle_amount_display' => $this->moneyDisplay((float) $tx->total_amount, $tx->currency),
            'date_display' => $tx->completed_at?->format('jS M, Y - h:i A') ?? $tx->created_at?->format('jS M, Y - h:i A') ?? '',
        ];
    }

    protected function moneyDisplay(float $amount, string $currency): string
    {
        $cur = strtoupper($currency);
        if ($cur === 'NGN') {
            return '₦'.number_format($amount, 0, '.', ',');
        }

        return $cur.' '.number_format($amount, 2, '.', ',');
    }
}
