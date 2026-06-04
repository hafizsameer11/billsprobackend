<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Models\ServiceProfitSetting;
use App\Models\Transaction;
use App\Services\Admin\AdminAuditService;
use App\Services\Admin\PricingCatalogService;
use App\Services\Admin\ProfitReportingService;
use App\Services\Admin\TransactionPlatformRateSnapshot;
use App\Services\Admin\TransactionRevenueClassifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminProfitController extends Controller
{
    public function __construct(
        protected ProfitReportingService $profit,
        protected PricingCatalogService $catalog,
        protected AdminAuditService $audit,
        protected TransactionPlatformRateSnapshot $rateSnapshot,
        protected TransactionRevenueClassifier $revenueClassifier,
    ) {}

    public function catalog(): JsonResponse
    {
        return ResponseHelper::success($this->catalog->buildCatalog(), 'Pricing catalog.');
    }

    public function settings(): JsonResponse
    {
        $rows = ServiceProfitSetting::query()
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get()
            ->map(fn (ServiceProfitSetting $s) => $this->formatSetting($s));

        return ResponseHelper::success($rows->values()->all(), 'Profit settings.');
    }

    public function updateSetting(Request $request, string $serviceKey): JsonResponse
    {
        $setting = ServiceProfitSetting::query()->where('service_key', $serviceKey)->firstOrFail();

        $data = $request->validate([
            'fixed_fee' => ['required', 'numeric', 'min:0'],
            'percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'percentage_basis' => ['required', 'string', Rule::in(['amount', 'total_amount', 'fee', 'ngn_notional'])],
            'margin_mode' => ['sometimes', 'string', Rule::in(['ledger_rule', 'charge_minus_cost', 'commission'])],
            'provider_cost_ngn' => ['nullable', 'numeric', 'min:0'],
            'provider_cost_usd' => ['nullable', 'numeric', 'min:0'],
            'provider_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'provider_pct_cap_ngn' => ['nullable', 'numeric', 'min:0'],
            'linked_rate_slug' => ['nullable', 'string', 'max:191'],
            'is_active' => ['required', 'boolean'],
        ]);

        $before = $setting->only(array_keys($data));
        $setting->update($data);

        $this->audit->log((int) $request->user()->id, 'profit_setting.update', $setting, [
            'before' => $before,
            'after' => $setting->only(array_keys($data)),
        ], $request);

        return ResponseHelper::success($this->formatSetting($setting->fresh()), 'Setting updated.');
    }

    public function transactions(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));

        $q = Transaction::query()->with('user:id,name,first_name,last_name,email')->orderByDesc('id');

        if ($request->filled('user_id')) {
            $q->where('user_id', (int) $request->query('user_id'));
        }
        if ($request->filled('type')) {
            $q->where('type', (string) $request->query('type'));
        }
        if ($request->filled('currency')) {
            $q->where('currency', (string) $request->query('currency'));
        }
        if (! $request->has('status')) {
            $q->where('status', 'completed');
        } elseif ((string) $request->query('status') !== 'all') {
            $q->where('status', (string) $request->query('status'));
        }
        if ($request->filled('from')) {
            $q->whereDate('created_at', '>=', (string) $request->query('from'));
        }
        if ($request->filled('to')) {
            $q->whereDate('created_at', '<=', (string) $request->query('to'));
        }
        if ($request->filled('search')) {
            $s = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim((string) $request->query('search'))).'%';
            $q->where(function ($w) use ($s) {
                $w->where('transaction_id', 'like', $s)
                    ->orWhere('reference', 'like', $s)
                    ->orWhere('description', 'like', $s)
                    ->orWhere('type', 'like', $s)
                    ->orWhereHas('user', function ($u) use ($s) {
                        $u->where('name', 'like', $s)
                            ->orWhere('email', 'like', $s);
                    });
            });
        }

        $settingsByKey = $this->profit->settingsByKey();
        $summary = $this->profit->summarize($q, $settingsByKey);

        $paginator = $q->paginate($perPage);
        $paginator->getCollection()->transform(function (Transaction $t) use ($settingsByKey) {
            $profit = $this->profit->computeForTransaction($t, $settingsByKey);
            $revenue = $this->revenueClassifier->classify($t);

            return [
                'id' => $t->id,
                'transaction_id' => $t->transaction_id,
                'description' => $t->description,
                'type' => $t->type,
                'category' => $t->category,
                'status' => $t->status,
                'currency' => $t->currency,
                'amount' => (string) $t->amount,
                'fee' => (string) $t->fee,
                'total_amount' => (string) $t->total_amount,
                'reference' => $t->reference,
                'created_at' => $t->created_at?->toIso8601String(),
                'user' => $t->user ? [
                    'id' => $t->user->id,
                    'display_name' => trim((string) ($t->user->name ?? '')) !== ''
                        ? (string) $t->user->name
                        : trim(((string) ($t->user->first_name ?? '')).' '.((string) ($t->user->last_name ?? ''))),
                    'email' => $t->user->email,
                ] : null,
                'profit' => $profit,
                'revenue' => $revenue,
                'rate_from_admin' => $this->rateSnapshot->forTransaction($t),
                'virtual_card_scheme' => in_array($t->type, ['card_creation', 'card_funding'], true) && is_array($t->metadata)
                    ? (isset($t->metadata['card_scheme']) ? (string) $t->metadata['card_scheme'] : null)
                    : null,
            ];
        });

        return ResponseHelper::success([
            'summary' => $summary,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'data' => $paginator->getCollection()->values()->all(),
        ], 'Profit transactions.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatSetting(ServiceProfitSetting $s): array
    {
        return [
            'id' => $s->id,
            'service_key' => $s->service_key,
            'label' => $s->label,
            'fixed_fee' => (string) $s->fixed_fee,
            'percentage' => (string) $s->percentage,
            'percentage_basis' => $s->percentage_basis,
            'is_active' => $s->is_active,
            'sort_order' => $s->sort_order,
            'margin_mode' => $s->margin_mode ?? 'ledger_rule',
            'provider_cost_ngn' => $s->provider_cost_ngn !== null ? (string) $s->provider_cost_ngn : null,
            'provider_cost_usd' => $s->provider_cost_usd !== null ? (string) $s->provider_cost_usd : null,
            'provider_pct' => $s->provider_pct !== null ? (string) $s->provider_pct : null,
            'provider_pct_cap_ngn' => $s->provider_pct_cap_ngn !== null ? (string) $s->provider_pct_cap_ngn : null,
            'linked_rate_slug' => $s->linked_rate_slug,
            'updated_at' => $s->updated_at?->toIso8601String(),
        ];
    }
}
