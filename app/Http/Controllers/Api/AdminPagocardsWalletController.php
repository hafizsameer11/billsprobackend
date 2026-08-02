<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Models\PagocardsWalletRecharge;
use App\Models\PlatformRate;
use App\Services\Admin\PagocardsWalletRechargeService;
use App\Services\Platform\PlatformRateResolver;
use App\Services\VirtualCard\PagocardsAdminApiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class AdminPagocardsWalletController extends Controller
{
    public function __construct(
        protected PagocardsWalletRechargeService $recharges,
        protected PagocardsAdminApiClient $adminApi,
        protected PlatformRateResolver $rates,
    ) {}

    public function summary(): JsonResponse
    {
        $latest = $this->recharges->latestRecharge();
        $visaFund = $this->rates->findVirtualCard('visa_fund');

        try {
            $walletBalances = $this->adminApi->getWalletBalances();
        } catch (\Throwable) {
            $walletBalances = [
                'visa_wallet_balance' => null,
                'master_wallet_balance' => null,
            ];
        }

        return ResponseHelper::success([
            'pagocards_wallet' => $walletBalances,
            'current_true_rate' => $this->recharges->currentTrueRate(),
            'current_true_rate_display' => $this->formatRate($this->recharges->currentTrueRate()),
            'last_recharge' => $latest ? $this->presentRecharge($latest) : null,
            'visa_fund_rates' => $this->presentVisaFundRates($visaFund),
            'historical_backfill_completed' => app(\App\Services\Admin\CardLoadProfitBackfillService::class)->hasCompletedHistoricalBackfill(),
            'awaiting_first_recharge_for_history' => ! PagocardsWalletRecharge::query()->exists(),
        ], 'Pagocards wallet summary retrieved.');
    }

    public function recharges(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));

        $paginator = PagocardsWalletRecharge::query()
            ->with('creator:id,name,email')
            ->orderByDesc('recharged_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        $paginator->getCollection()->transform(fn (PagocardsWalletRecharge $row) => $this->presentRecharge($row));

        return ResponseHelper::success($paginator, 'Pagocards wallet recharges retrieved.');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ngn_spent' => ['required', 'numeric', 'min:0.01'],
            'usd_credited' => ['required', 'numeric', 'min:0.0001'],
            'usd_gross' => ['nullable', 'numeric', 'min:0.0001'],
            'recharged_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $result = $this->recharges->create($validated, $request->user()?->id);
            $recharge = $result['recharge'];
            $historicalBackfill = $result['historical_backfill'];
        } catch (InvalidArgumentException $e) {
            return ResponseHelper::error($e->getMessage(), 422);
        }

        $recharge->load('creator:id,name,email');

        return ResponseHelper::success([
            'recharge' => $this->presentRecharge($recharge),
            'historical_backfill' => $historicalBackfill,
        ], $historicalBackfill && ! ($historicalBackfill['skipped'] ?? false)
            ? 'Pagocards wallet recharge logged and historical card-load profit backfilled.'
            : 'Pagocards wallet recharge logged.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentRecharge(PagocardsWalletRecharge $row): array
    {
        return [
            'id' => $row->id,
            'ngn_spent' => (float) $row->ngn_spent,
            'ngn_spent_display' => '₦'.number_format((float) $row->ngn_spent, 2),
            'usd_gross' => $row->usd_gross !== null ? (float) $row->usd_gross : null,
            'usd_credited' => (float) $row->usd_credited,
            'usd_credited_display' => '$'.number_format((float) $row->usd_credited, 2),
            'true_rate_ngn_per_usd' => (float) $row->true_rate_ngn_per_usd,
            'true_rate_display' => $this->formatRate((float) $row->true_rate_ngn_per_usd),
            'recharged_at' => $row->recharged_at?->toIso8601String(),
            'notes' => $row->notes,
            'created_by' => $row->created_by,
            'creator' => $row->relationLoaded('creator') && $row->creator
                ? ['id' => $row->creator->id, 'name' => $row->creator->name, 'email' => $row->creator->email]
                : null,
            'created_at' => $row->created_at?->toIso8601String(),
            'applied_to_history_at' => $row->applied_to_history_at?->toIso8601String(),
            'history_backfill_count' => $row->history_backfill_count,
            'db_backup_path' => $row->db_backup_path,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function presentVisaFundRates(?PlatformRate $rate): ?array
    {
        if (! $rate) {
            return null;
        }

        $trueRate = $this->recharges->currentTrueRate();
        $customerRate = $rate->exchange_rate_ngn_per_usd !== null
            ? (float) $rate->exchange_rate_ngn_per_usd
            : null;
        $customerFeeUsd = $rate->fee_usd !== null ? (float) $rate->fee_usd : null;
        $providerFeeUsd = $rate->provider_cost_usd !== null ? (float) $rate->provider_cost_usd : null;
        $providerPct = $rate->provider_pct !== null ? (float) $rate->provider_pct : null;

        $marginHint = null;
        if ($trueRate !== null && $customerRate !== null && $customerRate > 0) {
            $fxSpreadPct = round((($customerRate - $trueRate) / $trueRate) * 100, 2);
            $marginHint = "Customer rate is {$fxSpreadPct}% above true recharge rate.";
        }

        return [
            'customer_fee_usd' => $customerFeeUsd,
            'customer_rate_ngn_per_usd' => $customerRate,
            'provider_cost_usd' => $providerFeeUsd,
            'provider_pct' => $providerPct,
            'rates_page_path' => '/rates/visa-virtual-card',
            'margin_hint' => $marginHint,
        ];
    }

    protected function formatRate(?float $rate): ?string
    {
        if ($rate === null) {
            return null;
        }

        return '₦'.number_format($rate, 2).'/USD';
    }
}
