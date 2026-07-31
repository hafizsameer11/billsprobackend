<?php

namespace App\Services\Admin;

use App\Models\Transaction;
use App\Models\User;
use App\Models\VirtualCardTransaction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

/**
 * "What happened today?" — a single-day operator view of every money movement:
 * what came in, what went out, what is still pending, and how it compares to yesterday.
 */
class DaybookReportService
{
    /** Naira types that add money to a user wallet (credit side uses `amount`). */
    private const CREDIT_TYPES = ['deposit', 'card_refund', 'refund', 'reversal', 'bonus', 'cashback', 'admin_credit'];

    /** Naira types that take money out of a user wallet (debit side uses `total_amount`). */
    private const DEBIT_TYPES = ['withdrawal', 'bill_payment', 'card_creation', 'card_funding', 'admin_debit'];

    /**
     * Book-keeping rows that explain a wallet correction rather than a new money movement,
     * so they are listed on their own instead of distorting money in / money out.
     */
    private const NOTE_TYPES = ['adjustment'];

    /** Virtual-card ledger types that represent real merchant spend. */
    private const CARD_SPEND_TYPES = ['payment', 'settlement', 'purchase'];

    private const CRYPTO_TYPES = ['crypto_deposit', 'crypto_withdrawal', 'crypto_buy', 'crypto_sell', 'external_send'];

    /** Types the day book presents as its own headline row, in display order. */
    private const HEADLINE_TYPES = [
        'deposit' => ['label' => 'Deposits', 'direction' => 'in'],
        'card_refund' => ['label' => 'Card refunds', 'direction' => 'in'],
        'withdrawal' => ['label' => 'Withdrawals', 'direction' => 'out'],
        'bill_payment' => ['label' => 'Bill payments', 'direction' => 'out'],
        'card_funding' => ['label' => 'Card loads', 'direction' => 'out'],
        'card_creation' => ['label' => 'Card creation fees', 'direction' => 'out'],
    ];

    /**
     * Resolve the requested day, falling back to today in the app timezone.
     */
    public function normalizeDate(?string $date): Carbon
    {
        $raw = $date !== null ? trim($date) : '';
        if ($raw === '') {
            return Carbon::today();
        }

        try {
            return Carbon::parse($raw)->startOfDay();
        } catch (\Throwable) {
            return Carbon::today();
        }
    }

    /**
     * Everything an operator needs for one day.
     *
     * @return array<string, mixed>
     */
    public function day(?string $date, bool $includeTest = false): array
    {
        $day = $this->normalizeDate($date);
        $ymd = $day->toDateString();
        $prev = $day->copy()->subDay()->toDateString();

        $buckets = $this->buckets($ymd, $includeTest);
        $prevBuckets = $this->buckets($prev, $includeTest);

        $moneyIn = $buckets['in_total'];
        $moneyOut = $buckets['out_total'];
        $net = $moneyIn - $moneyOut;

        $cardSpend = $this->cardSpend($ymd, $includeTest);
        $today = Carbon::today();

        return [
            'day' => [
                'date' => $ymd,
                'label' => $day->isSameDay($today) ? 'Today' : ($day->isSameDay($today->copy()->subDay()) ? 'Yesterday' : $day->format('D, j M Y')),
                'long_label' => $day->format('l, j F Y'),
                'is_today' => $day->isSameDay($today),
                'prev_date' => $prev,
                'next_date' => $day->isSameDay($today) ? null : $day->copy()->addDay()->toDateString(),
                'timezone' => config('app.timezone'),
            ],
            'money_in' => [
                'total' => $moneyIn,
                'total_display' => $this->ngn($moneyIn),
                'count' => $buckets['in_count'],
                'lines' => $buckets['in_lines'],
            ],
            'money_out' => [
                'total' => $moneyOut,
                'total_display' => $this->ngn($moneyOut),
                'count' => $buckets['out_count'],
                'lines' => $buckets['out_lines'],
            ],
            'net' => [
                'amount' => $net,
                'amount_display' => $this->ngn($net),
                'direction' => $net >= 0 ? 'in' : 'out',
                'helper' => $net >= 0
                    ? 'More money came into wallets than left them on this day.'
                    : 'More money left wallets than came in on this day.',
            ],
            'fees_collected' => [
                'amount' => $buckets['fees'],
                'amount_display' => $this->ngn($buckets['fees']),
                'helper' => 'Fees charged on completed Naira transactions this day',
            ],
            'notes' => [
                'lines' => $buckets['note_lines'],
                'helper' => 'Book-keeping corrections — recorded for audit, not counted as money in or out.',
            ],
            'cards' => $cardSpend,
            'crypto' => $this->crypto($ymd, $includeTest),
            'people' => $this->people($ymd, $includeTest),
            'statuses' => $this->statusBreakdown($ymd, $includeTest),
            'hourly' => $this->hourly($ymd, $includeTest),
            'bill_breakdown' => $this->billBreakdown($ymd, $includeTest),
            'top_movements' => $this->topMovements($ymd, $includeTest),
            'versus_yesterday' => [
                'label' => $day->isSameDay($today) ? 'vs yesterday' : 'vs previous day',
                'money_in' => $this->delta($moneyIn, $prevBuckets['in_total']),
                'money_out' => $this->delta($moneyOut, $prevBuckets['out_total']),
                'fees' => $this->delta($buckets['fees'], $prevBuckets['fees']),
                'previous_money_in_display' => $this->ngn($prevBuckets['in_total']),
                'previous_money_out_display' => $this->ngn($prevBuckets['out_total']),
            ],
            'scope' => [
                'include_test' => $includeTest,
                'helper' => $includeTest
                    ? 'Showing every account, including staff and test accounts.'
                    : 'Staff and test accounts are excluded.',
            ],
        ];
    }

    /**
     * Every transaction on the day, newest first, with optional type/status/search filters.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function transactions(
        ?string $date,
        string $type = '',
        string $status = '',
        string $search = '',
        bool $includeTest = false,
        int $perPage = 25,
    ): LengthAwarePaginator {
        $ymd = $this->normalizeDate($date)->toDateString();
        $perPage = min(200, max(1, $perPage));

        $query = Transaction::query()
            ->with('user:id,name,first_name,last_name,email,phone_number')
            ->whereDate('created_at', $ymd)
            // Manual adjustments stay in the DB for audit but are not shown on the day book.
            ->whereNotIn('type', self::NOTE_TYPES)
            ->when($type !== '' && $type !== 'all', fn ($q) => $q->where('type', $type))
            ->when($status !== '' && $status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.$search.'%';
                $q->where(function ($w) use ($like) {
                    $w->where('transaction_id', 'like', $like)
                        ->orWhere('reference', 'like', $like)
                        ->orWhere('description', 'like', $like)
                        ->orWhereHas('user', function ($u) use ($like) {
                            $u->where('name', 'like', $like)
                                ->orWhere('email', 'like', $like)
                                ->orWhere('phone_number', 'like', $like);
                        });
                });
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (! $includeTest) {
            $query->whereIn('user_id', $this->realUserIdsQuery());
        }

        $paginator = $query->paginate($perPage);
        $paginator->getCollection()->transform(fn (Transaction $tx) => $this->presentTransaction($tx));

        return $paginator;
    }

    /**
     * Money movement per type for the day, split into credit and debit lines.
     *
     * @return array<string, mixed>
     */
    private function buckets(string $ymd, bool $includeTest): array
    {
        $rows = $this->nairaDayQuery($ymd, $includeTest)
            ->selectRaw('type, COUNT(*) as cnt, COALESCE(SUM(amount), 0) as amount_sum, COALESCE(SUM(total_amount), 0) as total_sum, COALESCE(SUM(fee), 0) as fee_sum')
            ->groupBy('type')
            ->get();

        $byType = [];
        $fees = 0.0;
        foreach ($rows as $row) {
            $type = (string) $row->type;
            $isCredit = in_array($type, self::CREDIT_TYPES, true);
            $byType[$type] = [
                'count' => (int) $row->cnt,
                'amount' => (float) ($isCredit ? $row->amount_sum : $row->total_sum),
                'direction' => $isCredit ? 'in' : 'out',
            ];
            $fees += (float) $row->fee_sum;
        }

        $inLines = [];
        $outLines = [];
        $noteLines = [];
        $inTotal = 0.0;
        $outTotal = 0.0;
        $inCount = 0;
        $outCount = 0;

        foreach (self::HEADLINE_TYPES as $type => $meta) {
            $hit = $byType[$type] ?? ['count' => 0, 'amount' => 0.0, 'direction' => $meta['direction']];
            unset($byType[$type]);
            $line = [
                'type' => $type,
                'label' => $meta['label'],
                'amount' => $hit['amount'],
                'amount_display' => $this->ngn($hit['amount']),
                'count' => $hit['count'],
            ];
            if ($meta['direction'] === 'in') {
                $inLines[] = $line;
                $inTotal += $hit['amount'];
                $inCount += $hit['count'];
            } else {
                $outLines[] = $line;
                $outTotal += $hit['amount'];
                $outCount += $hit['count'];
            }
        }

        // Anything unexpected (manual credits, reversals, one-off types) still has to show up.
        foreach ($byType as $type => $hit) {
            if (in_array($type, self::CRYPTO_TYPES, true)) {
                continue;
            }
            $line = [
                'type' => $type,
                'label' => $this->humanizeType($type),
                'amount' => $hit['amount'],
                'amount_display' => $this->ngn($hit['amount']),
                'count' => $hit['count'],
            ];
            if (in_array($type, self::NOTE_TYPES, true)) {
                $line['pct'] = 0.0;
                $noteLines[] = $line;

                continue;
            }
            if ($hit['direction'] === 'in') {
                $inLines[] = $line;
                $inTotal += $hit['amount'];
                $inCount += $hit['count'];
            } else {
                $outLines[] = $line;
                $outTotal += $hit['amount'];
                $outCount += $hit['count'];
            }
        }

        return [
            'in_lines' => $this->withPercentages($inLines, $inTotal),
            'out_lines' => $this->withPercentages($outLines, $outTotal),
            'note_lines' => $noteLines,
            'in_total' => $inTotal,
            'out_total' => $outTotal,
            'in_count' => $inCount,
            'out_count' => $outCount,
            'fees' => $fees,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function withPercentages(array $lines, float $total): array
    {
        $divisor = max($total, 0.0001);

        return array_values(array_map(function (array $line) use ($divisor) {
            $line['pct'] = round(((float) $line['amount'] / $divisor) * 100, 1);

            return $line;
        }, $lines));
    }

    /**
     * @return array<string, mixed>
     */
    private function cardSpend(string $ymd, bool $includeTest): array
    {
        $query = VirtualCardTransaction::query()
            ->where('status', 'completed')
            ->whereDate('created_at', $ymd);
        if (! $includeTest) {
            $query->whereIn('user_id', $this->realUserIdsQuery());
        }

        $row = (clone $query)->selectRaw(
            'COALESCE(SUM(CASE WHEN type IN (\''.implode("','", self::CARD_SPEND_TYPES).'\') THEN amount ELSE 0 END), 0) as spend,
             COALESCE(SUM(CASE WHEN type IN (\''.implode("','", self::CARD_SPEND_TYPES).'\') THEN 1 ELSE 0 END), 0) as spend_count,
             COALESCE(SUM(CASE WHEN type = \'fee\' THEN amount ELSE 0 END), 0) as fees,
             COALESCE(SUM(CASE WHEN type = \'fee\' THEN 1 ELSE 0 END), 0) as fees_count,
             COALESCE(SUM(CASE WHEN type = \'authorization_declined\' THEN 1 ELSE 0 END), 0) as declines'
        )->first();

        return [
            'spend_usd' => (float) ($row->spend ?? 0),
            'spend_usd_display' => $this->usd((float) ($row->spend ?? 0)),
            'spend_count' => (int) ($row->spend_count ?? 0),
            'card_fees_usd' => (float) ($row->fees ?? 0),
            'card_fees_usd_display' => $this->usd((float) ($row->fees ?? 0)),
            'card_fees_count' => (int) ($row->fees_count ?? 0),
            'declines' => (int) ($row->declines ?? 0),
            'helper' => 'Spend on virtual cards, settled in USD',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function crypto(string $ymd, bool $includeTest): array
    {
        $query = Transaction::query()
            ->where('status', 'completed')
            ->whereIn('type', self::CRYPTO_TYPES)
            ->whereDate('created_at', $ymd);
        if (! $includeTest) {
            $query->whereIn('user_id', $this->realUserIdsQuery());
        }

        $row = $query->selectRaw("
            COALESCE(SUM(CASE WHEN type = 'crypto_deposit' THEN amount ELSE 0 END), 0) as deposits,
            COALESCE(SUM(CASE WHEN type IN ('crypto_withdrawal', 'external_send') THEN amount ELSE 0 END), 0) as withdrawals,
            COUNT(*) as tx_count
        ")->first();

        return [
            'deposits' => (float) ($row->deposits ?? 0),
            'deposits_display' => number_format((float) ($row->deposits ?? 0), 4, '.', ','),
            'withdrawals' => (float) ($row->withdrawals ?? 0),
            'withdrawals_display' => number_format((float) ($row->withdrawals ?? 0), 4, '.', ','),
            'tx_count' => (int) ($row->tx_count ?? 0),
            'helper' => 'Crypto activity (secondary — not part of the Naira money story)',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function people(string $ymd, bool $includeTest): array
    {
        $active = Transaction::query()
            ->whereDate('created_at', $ymd)
            ->whereNotIn('type', self::NOTE_TYPES);
        if (! $includeTest) {
            $active->whereIn('user_id', $this->realUserIdsQuery());
        }

        $signups = User::query()->whereDate('created_at', $ymd);
        if (! $includeTest) {
            $signups->whereIn('id', $this->realUserIdsQuery());
        }

        return [
            'active_users' => (int) $active->distinct()->count('user_id'),
            'new_users' => (int) $signups->count(),
        ];
    }

    /**
     * Completed / pending / failed split — pending and failed money is what operators chase.
     * Money in and money out stay separate, because a single combined total says nothing useful.
     *
     * @return list<array<string, mixed>>
     */
    private function statusBreakdown(string $ymd, bool $includeTest): array
    {
        $query = Transaction::query()
            ->where('currency', 'NGN')
            ->whereDate('created_at', $ymd)
            ->whereNotIn('type', self::NOTE_TYPES);
        if (! $includeTest) {
            $query->whereIn('user_id', $this->realUserIdsQuery());
        }

        $rows = $query
            ->selectRaw('status, COUNT(*) as cnt')
            ->selectRaw('COALESCE(SUM(CASE WHEN type IN (\''.implode("','", self::CREDIT_TYPES).'\') THEN amount ELSE 0 END), 0) as in_amount')
            ->selectRaw('COALESCE(SUM(CASE WHEN type IN (\''.implode("','", self::DEBIT_TYPES).'\') THEN total_amount ELSE 0 END), 0) as out_amount')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        // Always show the statuses an operator looks for, even at zero, so "nothing pending"
        // reads as a deliberate all-clear rather than a card that failed to load.
        $order = ['completed', 'pending', 'processing', 'failed', 'cancelled'];
        $out = [];
        foreach ($order as $status) {
            $row = $rows->get($status);
            $rows->forget($status);
            if ($row === null && ! in_array($status, ['completed', 'pending', 'failed'], true)) {
                continue;
            }
            $out[] = $this->statusLine(
                $status,
                (int) ($row->cnt ?? 0),
                (float) ($row->in_amount ?? 0),
                (float) ($row->out_amount ?? 0),
            );
        }
        foreach ($rows as $status => $row) {
            $out[] = $this->statusLine(
                (string) $status,
                (int) $row->cnt,
                (float) $row->in_amount,
                (float) $row->out_amount,
            );
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function statusLine(string $status, int $count, float $in, float $out): array
    {
        return [
            'status' => $status,
            'label' => ucfirst(str_replace('_', ' ', $status)),
            'count' => $count,
            'in_amount' => $in,
            'in_display' => $this->ngn($in),
            'out_amount' => $out,
            'out_display' => $this->ngn($out),
        ];
    }

    /**
     * 24 hourly buckets so the shape of the day is visible at a glance.
     *
     * @return list<array<string, mixed>>
     */
    private function hourly(string $ymd, bool $includeTest): array
    {
        $rows = $this->nairaDayQuery($ymd, $includeTest)
            ->whereNotIn('type', self::NOTE_TYPES)
            ->selectRaw('HOUR(created_at) as h, COUNT(*) as cnt')
            ->selectRaw('COALESCE(SUM(CASE WHEN type IN (\''.implode("','", self::CREDIT_TYPES).'\') THEN amount ELSE 0 END), 0) as money_in')
            ->selectRaw('COALESCE(SUM(CASE WHEN type IN (\''.implode("','", self::DEBIT_TYPES).'\') THEN total_amount ELSE 0 END), 0) as money_out')
            ->groupBy('h')
            ->get()
            ->keyBy('h');

        $out = [];
        for ($h = 0; $h < 24; $h++) {
            $row = $rows->get($h);
            $in = (float) ($row->money_in ?? 0);
            $outAmt = (float) ($row->money_out ?? 0);
            $out[] = [
                'hour' => $h,
                'label' => str_pad((string) $h, 2, '0', STR_PAD_LEFT).':00',
                'money_in' => $in,
                'money_in_display' => $this->ngn($in),
                'money_out' => $outAmt,
                'money_out_display' => $this->ngn($outAmt),
                'count' => (int) ($row->cnt ?? 0),
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function billBreakdown(string $ymd, bool $includeTest): array
    {
        $query = Transaction::query()
            ->where('status', 'completed')
            ->where('currency', 'NGN')
            ->where('type', 'bill_payment')
            ->whereDate('created_at', $ymd);
        if (! $includeTest) {
            $query->whereIn('user_id', $this->realUserIdsQuery());
        }

        $rows = $query
            ->selectRaw("COALESCE(NULLIF(category, ''), 'other') as category")
            ->selectRaw('COALESCE(SUM(total_amount), 0) as amount, COUNT(*) as cnt')
            ->groupBy('category')
            ->orderByDesc('amount')
            ->limit(12)
            ->get();

        $total = max((float) $rows->sum('amount'), 0.0001);

        return $rows->map(fn ($row) => [
            'category' => (string) $row->category,
            'label' => $this->humanizeCategory((string) $row->category),
            'amount' => (float) $row->amount,
            'amount_display' => $this->ngn((float) $row->amount),
            'count' => (int) $row->cnt,
            'pct' => round(((float) $row->amount / $total) * 100, 1),
        ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function topMovements(string $ymd, bool $includeTest): array
    {
        $query = Transaction::query()
            ->with('user:id,name,first_name,last_name,email,phone_number')
            ->where('status', 'completed')
            ->where('currency', 'NGN')
            ->whereDate('created_at', $ymd)
            ->whereNotIn('type', self::NOTE_TYPES);
        if (! $includeTest) {
            $query->whereIn('user_id', $this->realUserIdsQuery());
        }

        return $query
            ->orderByDesc('total_amount')
            ->limit(5)
            ->get()
            ->map(fn (Transaction $tx) => $this->presentTransaction($tx))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function presentTransaction(Transaction $tx): array
    {
        $type = (string) $tx->type;
        $isCredit = in_array($type, self::CREDIT_TYPES, true);
        $isNote = in_array($type, self::NOTE_TYPES, true);
        $amount = $isCredit ? (float) $tx->amount : (float) $tx->total_amount;
        $isNaira = strtoupper((string) $tx->currency) === 'NGN';
        $user = $tx->user;

        return [
            'id' => (int) $tx->id,
            'transaction_id' => (string) $tx->transaction_id,
            'user_id' => (int) $tx->user_id,
            'user_name' => $user !== null ? $this->displayName($user) : 'User #'.$tx->user_id,
            'user_email' => $user?->email,
            'type' => $type,
            'type_label' => $this->humanizeType($type),
            'direction' => $isNote ? 'note' : ($isCredit ? 'in' : 'out'),
            'category' => $tx->category,
            'category_label' => $tx->category !== null ? $this->humanizeCategory((string) $tx->category) : null,
            'status' => (string) $tx->status,
            'currency' => (string) $tx->currency,
            'amount' => $amount,
            'amount_display' => $isNaira ? $this->ngn($amount) : number_format($amount, 4, '.', ',').' '.$tx->currency,
            'fee' => (float) $tx->fee,
            'fee_display' => $isNaira ? $this->ngn((float) $tx->fee) : number_format((float) $tx->fee, 4, '.', ','),
            'description' => $tx->description,
            'reference' => $tx->reference,
            'time' => $tx->created_at?->format('H:i'),
            'created_at' => $tx->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function delta(float $current, float $previous): array
    {
        $diff = $current - $previous;
        $pct = $previous > 0 ? round(($diff / $previous) * 100, 1) : null;

        return [
            'previous' => $previous,
            'diff' => $diff,
            'diff_display' => $this->ngn($diff),
            'pct' => $pct,
            'pct_display' => $pct === null
                ? ($current > 0 ? 'new' : '—')
                : ($pct > 0 ? '+'.$pct.'%' : $pct.'%'),
            'direction' => $diff > 0 ? 'up' : ($diff < 0 ? 'down' : 'flat'),
        ];
    }

    /**
     * Completed Naira movements for the day.
     *
     * @return \Illuminate\Database\Eloquent\Builder<Transaction>
     */
    private function nairaDayQuery(string $ymd, bool $includeTest)
    {
        $query = Transaction::query()
            ->where('status', 'completed')
            ->where('currency', 'NGN')
            ->whereDate('created_at', $ymd);

        if (! $includeTest) {
            $query->whereIn('user_id', $this->realUserIdsQuery());
        }

        return $query;
    }

    /**
     * Staff / sandbox accounts that would otherwise distort the day's numbers.
     *
     * @return list<string>
     */
    private function excludedEmailPatterns(): array
    {
        return [
            '%hmstech%',
            '%@test.com',
            'test@%',
            'testing%',
            'admin@%',
            'annaleona90@gmail.com',
        ];
    }

    private function realUserIdsQuery()
    {
        return User::query()
            ->select('id')
            ->where(function ($q) {
                $q->where('is_admin', false)->orWhereNull('is_admin');
            })
            ->where(function ($q) {
                $q->whereNull('email')
                    ->orWhere(function ($q2) {
                        foreach ($this->excludedEmailPatterns() as $pattern) {
                            $q2->where('email', 'not like', $pattern);
                        }
                    });
            });
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

    private function humanizeType(string $type): string
    {
        $map = [
            'deposit' => 'Deposit',
            'withdrawal' => 'Withdrawal',
            'bill_payment' => 'Bill payment',
            'card_creation' => 'Card creation fee',
            'card_funding' => 'Card load',
            'card_refund' => 'Card refund',
            'adjustment' => 'Manual adjustment',
            'admin_credit' => 'Admin credit',
            'admin_debit' => 'Admin debit',
            'crypto_deposit' => 'Crypto deposit',
            'crypto_withdrawal' => 'Crypto withdrawal',
            'crypto_buy' => 'Crypto buy',
            'crypto_sell' => 'Crypto sell',
            'external_send' => 'External send',
            'reversal' => 'Reversal',
            'refund' => 'Refund',
            'bonus' => 'Bonus',
            'cashback' => 'Cashback',
            'flush' => 'Treasury flush',
        ];

        return $map[$type] ?? ucwords(str_replace('_', ' ', $type));
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
