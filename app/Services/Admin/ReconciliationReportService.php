<?php

namespace App\Services\Admin;

use App\Models\FiatWallet;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VirtualCard;
use App\Models\VirtualCardTransaction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Plain-language money-story aggregates for admin reconciliation.
 * Focus: Naira wallet flows + virtual card USD; crypto is a thin secondary strip.
 */
class ReconciliationReportService
{
    /** Flag "Needs review" when absolute residual exceeds this (NGN). */
    private const REVIEW_THRESHOLD_NGN = 100.0;

    /**
     * @return array{from: ?string, to: ?string}
     */
    public function normalizeRange(?string $from, ?string $to): array
    {
        $from = $from !== null && trim($from) !== '' ? trim($from) : null;
        $to = $to !== null && trim($to) !== '' ? trim($to) : null;

        return ['from' => $from, 'to' => $to];
    }

    /**
     * Platform-wide overview for a date range (empty = all time).
     *
     * @return array<string, mixed>
     */
    public function overview(?string $from, ?string $to): array
    {
        $range = $this->normalizeRange($from, $to);
        $buckets = $this->aggregateNairaBuckets(null, $range['from'], $range['to']);
        $cardSpendUsd = $this->sumCardSpendUsd(null, $range['from'], $range['to']);
        $nairaBalance = (float) FiatWallet::query()->where('currency', 'NGN')->sum('balance');
        $cardBalanceUsd = (float) VirtualCard::query()->sum('balance');
        $crypto = $this->aggregateCryptoStrip(null, $range['from'], $range['to']);
        $billBreakdown = $this->billCategoryBreakdown(null, $range['from'], $range['to']);

        $check = $this->buildCheck(
            $buckets,
            $nairaBalance,
            $range['from'] === null && $range['to'] === null
        );

        $moneyOut = $buckets['withdrawn'] + $buckets['bill_payments'] + $buckets['card_creation_fees'] + $buckets['card_funding'];

        return [
            'period' => [
                'from' => $range['from'],
                'to' => $range['to'],
                'label' => $this->periodLabel($range['from'], $range['to']),
            ],
            'money_in' => [
                'deposited' => $buckets['deposited'],
                'deposited_display' => $this->ngn($buckets['deposited']),
                'deposited_count' => $buckets['deposited_count'],
                'helper' => 'Naira users added to their wallets',
            ],
            'money_out' => [
                'total' => $moneyOut,
                'total_display' => $this->ngn($moneyOut),
                'withdrawn' => $buckets['withdrawn'],
                'withdrawn_display' => $this->ngn($buckets['withdrawn']),
                'withdrawn_count' => $buckets['withdrawn_count'],
                'bill_payments' => $buckets['bill_payments'],
                'bill_payments_display' => $this->ngn($buckets['bill_payments']),
                'bill_payments_count' => $buckets['bill_payments_count'],
                'card_creation_fees' => $buckets['card_creation_fees'],
                'card_creation_fees_display' => $this->ngn($buckets['card_creation_fees']),
                'card_creation_count' => $buckets['card_creation_count'],
                'card_funding' => $buckets['card_funding'],
                'card_funding_display' => $this->ngn($buckets['card_funding']),
                'card_funding_count' => $buckets['card_funding_count'],
                'helper' => 'Withdrawals, bills, and money moved onto virtual cards',
            ],
            'still_held' => [
                'naira_balance' => $nairaBalance,
                'naira_balance_display' => $this->ngn($nairaBalance),
                'card_balance_usd' => $cardBalanceUsd,
                'card_balance_usd_display' => $this->usd($cardBalanceUsd),
                'helper' => 'Current balances (not limited to the date range)',
            ],
            'cards' => [
                'spent_usd' => $cardSpendUsd,
                'spent_usd_display' => $this->usd($cardSpendUsd),
                'spent_count' => $this->countCardSpend(null, $range['from'], $range['to']),
                'helper' => 'Merchant spend on virtual cards (USD)',
            ],
            'fees_collected' => [
                'amount' => $buckets['fees'],
                'amount_display' => $this->ngn($buckets['fees']),
                'helper' => 'Fees charged on completed Naira transactions',
            ],
            'where_money_went' => $this->whereMoneyWentBars($buckets, $moneyOut),
            'bill_breakdown' => $billBreakdown,
            'crypto' => $crypto,
            'check' => $check,
        ];
    }

    /**
     * Paginated per-user rollup.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function usersOverview(?string $from, ?string $to, string $search = '', int $perPage = 25): LengthAwarePaginator
    {
        $range = $this->normalizeRange($from, $to);
        $perPage = min(100, max(1, $perPage));

        $txAgg = $this->nairaBucketsSubquery($range['from'], $range['to']);
        $cardSpendAgg = $this->cardSpendSubquery($range['from'], $range['to']);

        $nairaBalSub = FiatWallet::query()
            ->selectRaw('user_id, COALESCE(SUM(balance), 0) as naira_balance')
            ->where('currency', 'NGN')
            ->groupBy('user_id');

        $cardBalSub = VirtualCard::query()
            ->selectRaw('user_id, COALESCE(SUM(balance), 0) as card_balance_usd')
            ->groupBy('user_id');

        $query = User::query()
            ->leftJoinSub($txAgg, 'tx', 'users.id', '=', 'tx.user_id')
            ->leftJoinSub($cardSpendAgg, 'vcs', 'users.id', '=', 'vcs.user_id')
            ->leftJoinSub($nairaBalSub, 'nb', 'users.id', '=', 'nb.user_id')
            ->leftJoinSub($cardBalSub, 'cb', 'users.id', '=', 'cb.user_id')
            ->select([
                'users.id',
                'users.name',
                'users.first_name',
                'users.last_name',
                'users.email',
                'users.phone_number',
            ])
            ->addSelect([
                DB::raw('COALESCE(tx.deposited, 0) as deposited'),
                DB::raw('COALESCE(tx.withdrawn, 0) as withdrawn'),
                DB::raw('COALESCE(tx.bill_payments, 0) as bill_payments'),
                DB::raw('COALESCE(tx.card_creation_fees, 0) as card_creation_fees'),
                DB::raw('COALESCE(tx.card_funding, 0) as card_funding'),
                DB::raw('COALESCE(tx.fees, 0) as fees'),
                DB::raw('COALESCE(vcs.card_spent_usd, 0) as card_spent_usd'),
                DB::raw('COALESCE(nb.naira_balance, 0) as naira_balance'),
                DB::raw('COALESCE(cb.card_balance_usd, 0) as card_balance_usd'),
            ])
            ->where(function ($q) {
                $q->whereNotNull('tx.user_id')
                    ->orWhereNotNull('vcs.user_id')
                    ->orWhere('nb.naira_balance', '>', 0)
                    ->orWhere('cb.card_balance_usd', '>', 0);
            })
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
            ->orderByDesc(DB::raw('COALESCE(tx.deposited, 0)'));

        $allTime = $range['from'] === null && $range['to'] === null;

        $paginator = $query->paginate($perPage);
        $paginator->getCollection()->transform(function ($row) use ($allTime) {
            $buckets = [
                'deposited' => (float) $row->deposited,
                'withdrawn' => (float) $row->withdrawn,
                'bill_payments' => (float) $row->bill_payments,
                'card_creation_fees' => (float) $row->card_creation_fees,
                'card_funding' => (float) $row->card_funding,
                'fees' => (float) $row->fees,
            ];
            $nairaBalance = (float) $row->naira_balance;
            $check = $this->buildCheck($buckets, $nairaBalance, $allTime);

            return [
                'user_id' => (int) $row->id,
                'display_name' => $this->displayName($row),
                'email' => $row->email,
                'phone_number' => $row->phone_number,
                'deposited' => $buckets['deposited'],
                'deposited_display' => $this->ngn($buckets['deposited']),
                'withdrawn' => $buckets['withdrawn'],
                'withdrawn_display' => $this->ngn($buckets['withdrawn']),
                'bill_payments' => $buckets['bill_payments'],
                'bill_payments_display' => $this->ngn($buckets['bill_payments']),
                'card_funding' => $buckets['card_funding'],
                'card_funding_display' => $this->ngn($buckets['card_funding']),
                'card_creation_fees' => $buckets['card_creation_fees'],
                'card_creation_fees_display' => $this->ngn($buckets['card_creation_fees']),
                'card_spent_usd' => (float) $row->card_spent_usd,
                'card_spent_usd_display' => $this->usd((float) $row->card_spent_usd),
                'naira_balance' => $nairaBalance,
                'naira_balance_display' => $this->ngn($nairaBalance),
                'card_balance_usd' => (float) $row->card_balance_usd,
                'card_balance_usd_display' => $this->usd((float) $row->card_balance_usd),
                'review_status' => $check['status'],
                'review_status_label' => $check['status_label'],
                'residual' => $check['residual'],
                'residual_display' => $check['residual_display'],
            ];
        });

        return $paginator;
    }

    /**
     * Full money story for one user.
     *
     * @return array<string, mixed>
     */
    public function userStory(User $user, ?string $from, ?string $to): array
    {
        $range = $this->normalizeRange($from, $to);
        $buckets = $this->aggregateNairaBuckets((int) $user->id, $range['from'], $range['to']);
        $cardSpendUsd = $this->sumCardSpendUsd((int) $user->id, $range['from'], $range['to']);
        $nairaBalance = (float) FiatWallet::query()
            ->where('user_id', $user->id)
            ->where('currency', 'NGN')
            ->sum('balance');
        $cardBalanceUsd = (float) VirtualCard::query()
            ->where('user_id', $user->id)
            ->sum('balance');
        $crypto = $this->aggregateCryptoStrip((int) $user->id, $range['from'], $range['to']);
        $billBreakdown = $this->billCategoryBreakdown((int) $user->id, $range['from'], $range['to']);
        $allTime = $range['from'] === null && $range['to'] === null;
        $check = $this->buildCheck($buckets, $nairaBalance, $allTime);
        $moneyOut = $buckets['withdrawn'] + $buckets['bill_payments'] + $buckets['card_creation_fees'] + $buckets['card_funding'];

        return [
            'user' => [
                'user_id' => (int) $user->id,
                'display_name' => $this->displayName($user),
                'email' => $user->email,
                'phone_number' => $user->phone_number,
            ],
            'period' => [
                'from' => $range['from'],
                'to' => $range['to'],
                'label' => $this->periodLabel($range['from'], $range['to']),
            ],
            'money_in' => [
                'deposited' => $buckets['deposited'],
                'deposited_display' => $this->ngn($buckets['deposited']),
                'deposited_count' => $buckets['deposited_count'],
                'helper' => 'Naira this user added to their wallet',
            ],
            'money_out' => [
                'total' => $moneyOut,
                'total_display' => $this->ngn($moneyOut),
                'withdrawn' => $buckets['withdrawn'],
                'withdrawn_display' => $this->ngn($buckets['withdrawn']),
                'withdrawn_count' => $buckets['withdrawn_count'],
                'bill_payments' => $buckets['bill_payments'],
                'bill_payments_display' => $this->ngn($buckets['bill_payments']),
                'bill_payments_count' => $buckets['bill_payments_count'],
                'card_creation_fees' => $buckets['card_creation_fees'],
                'card_creation_fees_display' => $this->ngn($buckets['card_creation_fees']),
                'card_creation_count' => $buckets['card_creation_count'],
                'card_funding' => $buckets['card_funding'],
                'card_funding_display' => $this->ngn($buckets['card_funding']),
                'card_funding_count' => $buckets['card_funding_count'],
                'helper' => 'Where Naira left this wallet',
            ],
            'still_held' => [
                'naira_balance' => $nairaBalance,
                'naira_balance_display' => $this->ngn($nairaBalance),
                'card_balance_usd' => $cardBalanceUsd,
                'card_balance_usd_display' => $this->usd($cardBalanceUsd),
                'helper' => 'What they still hold now',
            ],
            'cards' => [
                'spent_usd' => $cardSpendUsd,
                'spent_usd_display' => $this->usd($cardSpendUsd),
                'spent_count' => $this->countCardSpend((int) $user->id, $range['from'], $range['to']),
                'helper' => 'Merchant spend on their virtual cards (USD)',
            ],
            'fees_collected' => [
                'amount' => $buckets['fees'],
                'amount_display' => $this->ngn($buckets['fees']),
            ],
            'where_money_went' => $this->whereMoneyWentBars($buckets, $moneyOut),
            'bill_breakdown' => $billBreakdown,
            'crypto' => $crypto,
            'check' => $check,
            'links' => [
                'transactions' => '/transaction?user_id='.$user->id,
                'virtual_cards' => '/virtual-cards',
                'bill_payments' => '/bill-payments',
                'profile' => '/user/management/profile/'.$user->id,
            ],
        ];
    }

    /**
     * @return array<string, float|int>
     */
    private function aggregateNairaBuckets(?int $userId, ?string $from, ?string $to): array
    {
        $q = Transaction::query()
            ->where('status', 'completed')
            ->where('currency', 'NGN')
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->when($from !== null, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->whereDate('created_at', '<=', $to));

        $row = (clone $q)->selectRaw("
            COALESCE(SUM(CASE WHEN type = 'deposit' THEN amount ELSE 0 END), 0) as deposited,
            COALESCE(SUM(CASE WHEN type = 'deposit' THEN 1 ELSE 0 END), 0) as deposited_count,
            COALESCE(SUM(CASE WHEN type = 'withdrawal' THEN total_amount ELSE 0 END), 0) as withdrawn,
            COALESCE(SUM(CASE WHEN type = 'withdrawal' THEN 1 ELSE 0 END), 0) as withdrawn_count,
            COALESCE(SUM(CASE WHEN type = 'bill_payment' THEN total_amount ELSE 0 END), 0) as bill_payments,
            COALESCE(SUM(CASE WHEN type = 'bill_payment' THEN 1 ELSE 0 END), 0) as bill_payments_count,
            COALESCE(SUM(CASE WHEN type = 'card_creation' THEN total_amount ELSE 0 END), 0) as card_creation_fees,
            COALESCE(SUM(CASE WHEN type = 'card_creation' THEN 1 ELSE 0 END), 0) as card_creation_count,
            COALESCE(SUM(CASE WHEN type = 'card_funding' THEN total_amount ELSE 0 END), 0) as card_funding,
            COALESCE(SUM(CASE WHEN type = 'card_funding' THEN 1 ELSE 0 END), 0) as card_funding_count,
            COALESCE(SUM(fee), 0) as fees
        ")->first();

        return [
            'deposited' => (float) ($row->deposited ?? 0),
            'deposited_count' => (int) ($row->deposited_count ?? 0),
            'withdrawn' => (float) ($row->withdrawn ?? 0),
            'withdrawn_count' => (int) ($row->withdrawn_count ?? 0),
            'bill_payments' => (float) ($row->bill_payments ?? 0),
            'bill_payments_count' => (int) ($row->bill_payments_count ?? 0),
            'card_creation_fees' => (float) ($row->card_creation_fees ?? 0),
            'card_creation_count' => (int) ($row->card_creation_count ?? 0),
            'card_funding' => (float) ($row->card_funding ?? 0),
            'card_funding_count' => (int) ($row->card_funding_count ?? 0),
            'fees' => (float) ($row->fees ?? 0),
        ];
    }

    private function nairaBucketsSubquery(?string $from, ?string $to)
    {
        return Transaction::query()
            ->selectRaw('user_id')
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'deposit' THEN amount ELSE 0 END), 0) as deposited")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'withdrawal' THEN total_amount ELSE 0 END), 0) as withdrawn")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'bill_payment' THEN total_amount ELSE 0 END), 0) as bill_payments")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'card_creation' THEN total_amount ELSE 0 END), 0) as card_creation_fees")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'card_funding' THEN total_amount ELSE 0 END), 0) as card_funding")
            ->selectRaw('COALESCE(SUM(fee), 0) as fees')
            ->where('status', 'completed')
            ->where('currency', 'NGN')
            ->when($from !== null, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->whereDate('created_at', '<=', $to))
            ->groupBy('user_id');
    }

    private function cardSpendSubquery(?string $from, ?string $to)
    {
        return VirtualCardTransaction::query()
            ->selectRaw('user_id, COALESCE(SUM(amount), 0) as card_spent_usd')
            ->where('status', 'completed')
            ->where('type', 'payment')
            ->when($from !== null, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->whereDate('created_at', '<=', $to))
            ->groupBy('user_id');
    }

    private function sumCardSpendUsd(?int $userId, ?string $from, ?string $to): float
    {
        return (float) VirtualCardTransaction::query()
            ->where('status', 'completed')
            ->where('type', 'payment')
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->when($from !== null, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->whereDate('created_at', '<=', $to))
            ->sum('amount');
    }

    private function countCardSpend(?int $userId, ?string $from, ?string $to): int
    {
        return (int) VirtualCardTransaction::query()
            ->where('status', 'completed')
            ->where('type', 'payment')
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->when($from !== null, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->whereDate('created_at', '<=', $to))
            ->count();
    }

    /**
     * @return list<array{category: string, label: string, amount: float, amount_display: string, count: int}>
     */
    private function billCategoryBreakdown(?int $userId, ?string $from, ?string $to): array
    {
        $rows = Transaction::query()
            ->selectRaw("COALESCE(NULLIF(category, ''), 'other') as category")
            ->selectRaw('COALESCE(SUM(total_amount), 0) as amount')
            ->selectRaw('COUNT(*) as cnt')
            ->where('status', 'completed')
            ->where('currency', 'NGN')
            ->where('type', 'bill_payment')
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->when($from !== null, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->whereDate('created_at', '<=', $to))
            ->groupBy('category')
            ->orderByDesc('amount')
            ->limit(12)
            ->get();

        return $rows->map(function ($row) {
            $cat = (string) $row->category;

            return [
                'category' => $cat,
                'label' => $this->humanizeCategory($cat),
                'amount' => (float) $row->amount,
                'amount_display' => $this->ngn((float) $row->amount),
                'count' => (int) $row->cnt,
            ];
        })->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function aggregateCryptoStrip(?int $userId, ?string $from, ?string $to): array
    {
        $q = Transaction::query()
            ->where('status', 'completed')
            ->whereIn('type', ['crypto_deposit', 'crypto_withdrawal', 'crypto_buy', 'crypto_sell', 'external_send'])
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->when($from !== null, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->whereDate('created_at', '<=', $to));

        $row = (clone $q)->selectRaw("
            COALESCE(SUM(CASE WHEN type = 'crypto_deposit' THEN amount ELSE 0 END), 0) as deposits,
            COALESCE(SUM(CASE WHEN type IN ('crypto_withdrawal', 'external_send') THEN amount ELSE 0 END), 0) as withdrawals,
            COALESCE(SUM(CASE WHEN type = 'crypto_buy' THEN amount ELSE 0 END), 0) as buys,
            COALESCE(SUM(CASE WHEN type = 'crypto_sell' THEN amount ELSE 0 END), 0) as sells,
            COUNT(*) as tx_count
        ")->first();

        return [
            'deposits' => (float) ($row->deposits ?? 0),
            'deposits_display' => number_format((float) ($row->deposits ?? 0), 4, '.', ','),
            'withdrawals' => (float) ($row->withdrawals ?? 0),
            'withdrawals_display' => number_format((float) ($row->withdrawals ?? 0), 4, '.', ','),
            'buys' => (float) ($row->buys ?? 0),
            'sells' => (float) ($row->sells ?? 0),
            'tx_count' => (int) ($row->tx_count ?? 0),
            'helper' => 'Crypto activity (secondary — not part of the Naira money story)',
        ];
    }

    /**
     * @param  array<string, float|int>  $buckets
     * @return array<string, mixed>
     */
    private function buildCheck(array $buckets, float $nairaBalance, bool $allTime): array
    {
        $outflows = (float) $buckets['withdrawn']
            + (float) $buckets['bill_payments']
            + (float) $buckets['card_creation_fees']
            + (float) $buckets['card_funding'];
        $deposited = (float) $buckets['deposited'];

        if ($allTime) {
            $residual = $deposited - $outflows - $nairaBalance;
            $explanation = 'Deposited should roughly equal withdrawals + bills + card fees + card loads + current Naira balance.';
        } else {
            $residual = $deposited - $outflows;
            $explanation = 'For this date range: deposited minus money out (net flow into wallets). Current balances are shown separately.';
        }

        $needsReview = abs($residual) > self::REVIEW_THRESHOLD_NGN;

        return [
            'residual' => $residual,
            'residual_display' => $this->ngn($residual),
            'status' => $needsReview ? 'needs_review' : 'ok',
            'status_label' => $needsReview ? 'Needs review' : 'OK',
            'threshold_ngn' => self::REVIEW_THRESHOLD_NGN,
            'explanation' => $explanation,
            'all_time' => $allTime,
        ];
    }

    /**
     * @param  array<string, float|int>  $buckets
     * @return list<array{key: string, label: string, amount: float, amount_display: string, pct: float}>
     */
    private function whereMoneyWentBars(array $buckets, float $moneyOut): array
    {
        $items = [
            ['key' => 'withdrawn', 'label' => 'Withdrawals', 'amount' => (float) $buckets['withdrawn']],
            ['key' => 'bill_payments', 'label' => 'Bill payments', 'amount' => (float) $buckets['bill_payments']],
            ['key' => 'card_funding', 'label' => 'Loaded onto cards', 'amount' => (float) $buckets['card_funding']],
            ['key' => 'card_creation_fees', 'label' => 'Card creation fees', 'amount' => (float) $buckets['card_creation_fees']],
        ];

        $total = max($moneyOut, 0.0001);

        return array_map(function (array $item) use ($total) {
            return [
                'key' => $item['key'],
                'label' => $item['label'],
                'amount' => $item['amount'],
                'amount_display' => $this->ngn($item['amount']),
                'pct' => round(($item['amount'] / $total) * 100, 1),
            ];
        }, $items);
    }

    private function periodLabel(?string $from, ?string $to): string
    {
        if ($from === null && $to === null) {
            return 'All time';
        }
        if ($from !== null && $to !== null) {
            return $from.' → '.$to;
        }

        return ($from ?? '…').' → '.($to ?? '…');
    }

    private function displayName(object $row): string
    {
        $name = trim((string) ($row->name ?? ''));
        if ($name !== '') {
            return $name;
        }
        $composed = trim(($row->first_name ?? '').' '.($row->last_name ?? ''));

        return $composed !== '' ? $composed : 'User #'.($row->id ?? '');
    }

    private function humanizeCategory(string $category): string
    {
        $map = [
            'airtime' => 'Airtime',
            'data' => 'Data',
            'electricity' => 'Electricity',
            'cable' => 'Cable TV',
            'cable_tv' => 'Cable TV',
            'internet' => 'Internet',
            'betting' => 'Betting',
            'other' => 'Other',
        ];

        return $map[strtolower($category)] ?? ucwords(str_replace('_', ' ', $category));
    }

    private function ngn(float $amount): string
    {
        $abs = abs($amount);
        $formatted = '₦'.number_format($abs, 0, '.', ',');

        return $amount < 0 ? '-'.$formatted : $formatted;
    }

    private function usd(float $amount): string
    {
        return '$'.number_format($amount, 2, '.', ',');
    }
}
