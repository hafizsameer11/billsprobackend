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
     * Virtual-card ledger types that represent merchant spend (USD).
     * Prefer settlement/payment/purchase — exclude authorization to avoid double-counting with settlement.
     *
     * @var list<string>
     */
    private const CARD_SPEND_TYPES = ['payment', 'settlement', 'purchase'];

    /** Naira transaction types that add money to the wallet. */
    private const LEDGER_CREDIT_TYPES = ['deposit', 'card_refund', 'refund', 'reversal', 'bonus', 'cashback'];

    /** Naira transaction types that take money out of the wallet. */
    private const LEDGER_DEBIT_TYPES = ['withdrawal', 'bill_payment', 'card_creation', 'card_funding', 'card_decline_fee'];

    /**
     * Book-keeping rows that explain a wallet correction rather than a new money movement.
     * They are shown in the ledger but must not shift the running balance.
     *
     * @var list<string>
     */
    private const LEDGER_NOTE_TYPES = ['adjustment'];

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
     * Staff / sandbox / internal accounts that must not appear in reconciliation.
     * Keep Peter and other real early users — only exclude admin + known test inboxes.
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

    /**
     * Query of real customer user IDs (excludes admins + test/staff emails).
     */
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

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\User>|\Illuminate\Database\Query\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\User>|\Illuminate\Database\Query\Builder
     */
    private function excludeTestAndStaffUsers($query)
    {
        return $query
            ->where(function ($q) {
                $q->where('users.is_admin', false)->orWhereNull('users.is_admin');
            })
            ->where(function ($q) {
                $q->whereNull('users.email')
                    ->orWhere(function ($q2) {
                        foreach ($this->excludedEmailPatterns() as $pattern) {
                            $q2->where('users.email', 'not like', $pattern);
                        }
                    });
            });
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
        $realIds = $this->realUserIdsQuery();
        $nairaBalance = (float) FiatWallet::query()
            ->where('currency', 'NGN')
            ->whereIn('user_id', $realIds)
            ->sum('balance');
        $cardBalanceUsd = (float) VirtualCard::query()
            ->whereIn('user_id', $realIds)
            ->sum('balance');
        $crypto = $this->aggregateCryptoStrip(null, $range['from'], $range['to']);
        $billBreakdown = $this->billCategoryBreakdown(null, $range['from'], $range['to']);

        $isRange = $range['from'] !== null || $range['to'] !== null;
        $allTimeBuckets = $isRange ? $this->aggregateNairaBuckets(null, null, null) : $buckets;
        $check = $this->buildCheck($allTimeBuckets, $nairaBalance, $buckets, $isRange);

        $moneyOut = $this->sumMoneyOut($buckets);

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
                'card_refunds' => $buckets['card_refunds'],
                'card_refunds_display' => $this->ngn($buckets['card_refunds']),
                'card_refunds_count' => $buckets['card_refunds_count'],
                'total' => $buckets['deposited'] + $buckets['card_refunds'],
                'total_display' => $this->ngn($buckets['deposited'] + $buckets['card_refunds']),
                'helper' => 'Naira users added (deposits + card refunds)',
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
                'helper' => 'Merchant spend on virtual cards (USD): payment, settlement, purchase',
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
        // The balance check is always all-time, so a date filter cannot invent a gap.
        $isRange = $range['from'] !== null || $range['to'] !== null;
        $txAggAll = $isRange ? $this->nairaBucketsSubquery(null, null) : null;

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
            ->when($txAggAll !== null, fn ($q) => $q->leftJoinSub($txAggAll, 'txall', 'users.id', '=', 'txall.user_id'))
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
                DB::raw('COALESCE(tx.card_refunds, 0) as card_refunds'),
                DB::raw('COALESCE(tx.withdrawn, 0) as withdrawn'),
                DB::raw('COALESCE(tx.bill_payments, 0) as bill_payments'),
                DB::raw('COALESCE(tx.card_creation_fees, 0) as card_creation_fees'),
                DB::raw('COALESCE(tx.card_funding, 0) as card_funding'),
                DB::raw('COALESCE(tx.fees, 0) as fees'),
                DB::raw('COALESCE(vcs.card_spent_usd, 0) as card_spent_usd'),
                DB::raw('COALESCE(nb.naira_balance, 0) as naira_balance'),
                DB::raw('COALESCE(cb.card_balance_usd, 0) as card_balance_usd'),
            ])
            ->when($txAggAll !== null, fn ($q) => $q->addSelect([
                DB::raw('COALESCE(txall.deposited, 0) as all_deposited'),
                DB::raw('COALESCE(txall.card_refunds, 0) as all_card_refunds'),
                DB::raw('COALESCE(txall.withdrawn, 0) as all_withdrawn'),
                DB::raw('COALESCE(txall.bill_payments, 0) as all_bill_payments'),
                DB::raw('COALESCE(txall.card_creation_fees, 0) as all_card_creation_fees'),
                DB::raw('COALESCE(txall.card_funding, 0) as all_card_funding'),
            ]));
        $this->excludeTestAndStaffUsers($query);
        $query
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

        $paginator = $query->paginate($perPage);
        $paginator->getCollection()->transform(function ($row) use ($isRange) {
            $buckets = [
                'deposited' => (float) $row->deposited,
                'card_refunds' => (float) $row->card_refunds,
                'withdrawn' => (float) $row->withdrawn,
                'bill_payments' => (float) $row->bill_payments,
                'card_creation_fees' => (float) $row->card_creation_fees,
                'card_funding' => (float) $row->card_funding,
                'fees' => (float) $row->fees,
            ];
            $nairaBalance = (float) $row->naira_balance;
            $allTimeBuckets = $isRange ? [
                'deposited' => (float) $row->all_deposited,
                'card_refunds' => (float) $row->all_card_refunds,
                'withdrawn' => (float) $row->all_withdrawn,
                'bill_payments' => (float) $row->all_bill_payments,
                'card_creation_fees' => (float) $row->all_card_creation_fees,
                'card_funding' => (float) $row->all_card_funding,
            ] : $buckets;
            $check = $this->buildCheck($allTimeBuckets, $nairaBalance, $buckets, $isRange);

            return [
                'user_id' => (int) $row->id,
                'display_name' => $this->displayName($row),
                'email' => $row->email,
                'phone_number' => $row->phone_number,
                'deposited' => $buckets['deposited'],
                'deposited_display' => $this->ngn($buckets['deposited']),
                'card_refunds' => $buckets['card_refunds'],
                'card_refunds_display' => $this->ngn($buckets['card_refunds']),
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
        $isRange = $range['from'] !== null || $range['to'] !== null;
        $allTimeBuckets = $isRange ? $this->aggregateNairaBuckets((int) $user->id, null, null) : $buckets;
        $check = $this->buildCheck($allTimeBuckets, $nairaBalance, $buckets, $isRange);
        $moneyOut = $this->sumMoneyOut($buckets);

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
                'card_refunds' => $buckets['card_refunds'],
                'card_refunds_display' => $this->ngn($buckets['card_refunds']),
                'card_refunds_count' => $buckets['card_refunds_count'],
                'total' => $buckets['deposited'] + $buckets['card_refunds'],
                'total_display' => $this->ngn($buckets['deposited'] + $buckets['card_refunds']),
                'helper' => 'Naira this user added (deposits + card refunds)',
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
                'helper' => 'Merchant spend on their virtual cards (USD): payment, settlement, purchase',
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
     * Full chronological ledger for one user: every Naira movement with a running balance,
     * plus every virtual-card (USD) movement, so admins can trace each fee and spend.
     *
     * @return array<string, mixed>
     */
    public function userLedger(User $user, ?string $from, ?string $to): array
    {
        $range = $this->normalizeRange($from, $to);

        $nairaTx = Transaction::query()
            ->where('user_id', $user->id)
            ->where('currency', 'NGN')
            ->when($range['from'] !== null, fn ($q) => $q->whereDate('created_at', '>=', $range['from']))
            ->when($range['to'] !== null, fn ($q) => $q->whereDate('created_at', '<=', $range['to']))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get([
                'id', 'transaction_id', 'type', 'category', 'status', 'amount', 'fee',
                'total_amount', 'description', 'reference', 'created_at',
            ]);

        $rows = [];
        $running = 0.0;
        $unmapped = [];

        foreach ($nairaTx as $tx) {
            $type = (string) $tx->type;
            $isNote = in_array($type, self::LEDGER_NOTE_TYPES, true);
            $isCredit = ! $isNote && in_array($type, self::LEDGER_CREDIT_TYPES, true);
            $isDebit = ! $isNote && in_array($type, self::LEDGER_DEBIT_TYPES, true);
            $counts = $tx->status === 'completed' && ($isCredit || $isDebit);

            // Credits move `amount`; debits move `total_amount` (principal + fee).
            $movement = $isCredit ? (float) $tx->amount : (float) $tx->total_amount;

            if ($counts) {
                $running += $isCredit ? $movement : -$movement;
            }
            if (! $isCredit && ! $isDebit && ! $isNote) {
                $unmapped[$type] = ($unmapped[$type] ?? 0) + 1;
            }

            $rows[] = [
                'id' => 'ngn-'.$tx->id,
                'wallet' => 'naira',
                'wallet_label' => 'Naira wallet',
                'at' => optional($tx->created_at)->toIso8601String(),
                'type' => $type,
                'label' => $this->humanizeType($type),
                'category' => $tx->category,
                'status' => (string) $tx->status,
                'direction' => $isCredit ? 'in' : ($isDebit ? 'out' : ($isNote ? 'note' : 'unknown')),
                'amount' => (float) $tx->amount,
                'amount_display' => $this->ngn((float) $tx->amount),
                'fee' => (float) $tx->fee,
                'fee_display' => $this->ngn((float) $tx->fee),
                'total' => (float) $tx->total_amount,
                'total_display' => $this->ngn((float) $tx->total_amount),
                'signed_display' => $isNote
                    ? 'note '.$this->ngn((float) $tx->amount)
                    : ($isCredit ? '+' : ($isDebit ? '-' : '')).$this->ngn($movement),
                'counts_towards_balance' => $counts,
                'balance_after' => $counts ? $running : null,
                'balance_after_display' => $counts ? $this->ngn($running) : null,
                'description' => (string) ($tx->description ?? ''),
                'reference' => $tx->reference ?? $tx->transaction_id,
            ];
        }

        $cardTx = VirtualCardTransaction::query()
            ->where('user_id', $user->id)
            ->when($range['from'] !== null, fn ($q) => $q->whereDate('created_at', '>=', $range['from']))
            ->when($range['to'] !== null, fn ($q) => $q->whereDate('created_at', '<=', $range['to']))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get([
                'id', 'virtual_card_id', 'type', 'status', 'currency', 'amount', 'fee',
                'total_amount', 'description', 'reference', 'created_at',
            ]);

        $cardRows = [];
        foreach ($cardTx as $tx) {
            $type = (string) $tx->type;
            $isSpend = in_array($type, self::CARD_SPEND_TYPES, true);
            $isFee = $type === 'fee';
            $isLoad = in_array($type, ['fund', 'funding', 'topup'], true);

            $cardRows[] = [
                'id' => 'card-'.$tx->id,
                'wallet' => 'card',
                'wallet_label' => 'Card #'.$tx->virtual_card_id.' (USD)',
                'virtual_card_id' => (int) $tx->virtual_card_id,
                'at' => optional($tx->created_at)->toIso8601String(),
                'type' => $type,
                'label' => $this->humanizeType($type),
                'status' => (string) $tx->status,
                'direction' => $isLoad ? 'in' : (($isSpend || $isFee) ? 'out' : 'info'),
                'amount' => (float) $tx->amount,
                'amount_display' => $this->usd((float) $tx->amount),
                'fee' => (float) $tx->fee,
                'fee_display' => $this->usd((float) $tx->fee),
                'total' => (float) $tx->total_amount,
                'total_display' => $this->usd((float) $tx->total_amount),
                'description' => (string) ($tx->description ?? ''),
                'reference' => $tx->reference,
                'is_decline_fee' => $isFee && stripos((string) $tx->description, 'declin') !== false,
            ];
        }

        $actualBalance = (float) FiatWallet::query()
            ->where('user_id', $user->id)
            ->where('currency', 'NGN')
            ->sum('balance');
        $allTime = $range['from'] === null && $range['to'] === null;
        $drift = $allTime ? $running - $actualBalance : 0.0;

        return [
            'user' => [
                'user_id' => (int) $user->id,
                'display_name' => $this->displayName($user),
                'email' => $user->email,
            ],
            'period' => [
                'from' => $range['from'],
                'to' => $range['to'],
                'label' => $this->periodLabel($range['from'], $range['to']),
            ],
            'naira_rows' => $rows,
            'card_rows' => $cardRows,
            'totals' => [
                'naira_rows_count' => count($rows),
                'card_rows_count' => count($cardRows),
                'ledger_balance' => $running,
                'ledger_balance_display' => $this->ngn($running),
                'wallet_balance' => $actualBalance,
                'wallet_balance_display' => $this->ngn($actualBalance),
                'drift' => $drift,
                'drift_display' => $this->ngn($drift),
                'drift_note' => $allTime
                    ? 'Ledger balance should match the wallet balance when every movement is recorded.'
                    : 'Drift is only meaningful over all time.',
                'unmapped_types' => $unmapped,
            ],
        ];
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
            'payment' => 'Card payment',
            'settlement' => 'Card settlement',
            'purchase' => 'Card purchase',
            'authorization' => 'Card authorization',
            'authorization_declined' => 'Declined authorization',
            'fee' => 'Card fee',
            'fund' => 'Card top-up',
            'funding' => 'Card top-up',
            'verification' => 'Card verification',
            'reversal' => 'Reversal',
            'reversal_settled' => 'Reversal settled',
            'cross_border_settled' => 'Cross-border fee',
            'cross_border_pending' => 'Cross-border (pending)',
        ];

        return $map[$type] ?? ucwords(str_replace('_', ' ', $type));
    }

    /**
     * @return array<string, float|int>
     */
    /**
     * Scope a query to one user, or to all real (non-test/staff) users when $userId is null.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>|\Illuminate\Database\Query\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>|\Illuminate\Database\Query\Builder
     */
    private function scopeToUserOrRealUsers($query, ?int $userId)
    {
        if ($userId !== null) {
            return $query->where('user_id', $userId);
        }

        return $query->whereIn('user_id', $this->realUserIdsQuery());
    }

    private function aggregateNairaBuckets(?int $userId, ?string $from, ?string $to): array
    {
        $q = Transaction::query()
            ->where('status', 'completed')
            ->where('currency', 'NGN');
        $this->scopeToUserOrRealUsers($q, $userId);
        $q->when($from !== null, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->whereDate('created_at', '<=', $to));

        $row = (clone $q)->selectRaw("
            COALESCE(SUM(CASE WHEN type = 'deposit' THEN amount ELSE 0 END), 0) as deposited,
            COALESCE(SUM(CASE WHEN type = 'deposit' THEN 1 ELSE 0 END), 0) as deposited_count,
            COALESCE(SUM(CASE WHEN type = 'card_refund' THEN amount ELSE 0 END), 0) as card_refunds,
            COALESCE(SUM(CASE WHEN type = 'card_refund' THEN 1 ELSE 0 END), 0) as card_refunds_count,
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
            'card_refunds' => (float) ($row->card_refunds ?? 0),
            'card_refunds_count' => (int) ($row->card_refunds_count ?? 0),
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
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'card_refund' THEN amount ELSE 0 END), 0) as card_refunds")
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
            ->whereIn('type', self::CARD_SPEND_TYPES)
            ->when($from !== null, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->whereDate('created_at', '<=', $to))
            ->groupBy('user_id');
    }

    private function sumCardSpendUsd(?int $userId, ?string $from, ?string $to): float
    {
        $q = VirtualCardTransaction::query()
            ->where('status', 'completed')
            ->whereIn('type', self::CARD_SPEND_TYPES);
        $this->scopeToUserOrRealUsers($q, $userId);

        return (float) $q
            ->when($from !== null, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->whereDate('created_at', '<=', $to))
            ->sum('amount');
    }

    private function countCardSpend(?int $userId, ?string $from, ?string $to): int
    {
        $q = VirtualCardTransaction::query()
            ->where('status', 'completed')
            ->whereIn('type', self::CARD_SPEND_TYPES);
        $this->scopeToUserOrRealUsers($q, $userId);

        return (int) $q
            ->when($from !== null, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->whereDate('created_at', '<=', $to))
            ->count();
    }

    /**
     * @return list<array{category: string, label: string, amount: float, amount_display: string, count: int}>
     */
    private function billCategoryBreakdown(?int $userId, ?string $from, ?string $to): array
    {
        $q = Transaction::query()
            ->selectRaw("COALESCE(NULLIF(category, ''), 'other') as category")
            ->selectRaw('COALESCE(SUM(total_amount), 0) as amount')
            ->selectRaw('COUNT(*) as cnt')
            ->where('status', 'completed')
            ->where('currency', 'NGN')
            ->where('type', 'bill_payment');
        $this->scopeToUserOrRealUsers($q, $userId);
        $rows = $q
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
            ->whereIn('type', ['crypto_deposit', 'crypto_withdrawal', 'crypto_buy', 'crypto_sell', 'external_send']);
        $this->scopeToUserOrRealUsers($q, $userId);
        $q->when($from !== null, fn ($q) => $q->whereDate('created_at', '>=', $from))
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
     */
    private function sumMoneyIn(array $buckets): float
    {
        return (float) $buckets['deposited'] + (float) ($buckets['card_refunds'] ?? 0);
    }

    /**
     * @param  array<string, float|int>  $buckets
     */
    private function sumMoneyOut(array $buckets): float
    {
        return (float) $buckets['withdrawn']
            + (float) $buckets['bill_payments']
            + (float) $buckets['card_creation_fees']
            + (float) $buckets['card_funding'];
    }

    /**
     * Does the money add up? This always compares the *whole* history against the current
     * Naira balance — a date filter narrows the displayed figures but must never create a
     * fake gap, because money deposited inside the window is still sitting in the wallet.
     *
     * @param  array<string, float|int>  $allTimeBuckets  every completed Naira movement, ignoring the filter
     * @param  array<string, float|int>  $rangeBuckets  movements inside the selected period
     * @return array<string, mixed>
     */
    private function buildCheck(array $allTimeBuckets, float $nairaBalance, array $rangeBuckets, bool $isRange): array
    {
        $residual = $this->sumMoneyIn($allTimeBuckets) - $this->sumMoneyOut($allTimeBuckets) - $nairaBalance;
        $netFlow = $this->sumMoneyIn($rangeBuckets) - $this->sumMoneyOut($rangeBuckets);
        $needsReview = abs($residual) > self::REVIEW_THRESHOLD_NGN;

        return [
            'residual' => $residual,
            'residual_display' => $this->ngn($residual),
            'status' => $needsReview ? 'needs_review' : 'ok',
            'status_label' => $needsReview ? 'Gap '.$this->ngn($residual) : 'Balanced',
            'threshold_ngn' => self::REVIEW_THRESHOLD_NGN,
            'explanation' => 'All-time check: every deposit and card refund, minus withdrawals, bills, card creation fees and card loads, should equal the current Naira balance.',
            'all_time' => true,
            'net_flow' => $netFlow,
            'net_flow_display' => $this->ngn($netFlow),
            'net_flow_label' => $isRange
                ? 'Net into the Naira wallet in this period'
                : 'Net into the Naira wallet (all time)',
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
