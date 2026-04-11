<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Models\PlatformRate;
use App\Models\WalletCurrency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminPlatformRateController extends Controller
{
    public function meta(): JsonResponse
    {
        $fiatServices = [
            ['key' => 'deposit', 'label' => 'Deposit'],
            ['key' => 'withdrawal', 'label' => 'Withdrawal'],
            ['key' => 'bill_payment', 'label' => 'Bill Payment'],
        ];

        $billSubs = DB::table('bill_payment_categories')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['code', 'name'])
            ->map(fn ($r) => ['key' => $r->code, 'label' => $r->name])
            ->values()
            ->all();

        $cryptoServices = [
            ['key' => 'deposit', 'label' => 'Deposit'],
            ['key' => 'withdrawal', 'label' => 'Withdrawal'],
            ['key' => 'buy', 'label' => 'Buy'],
            ['key' => 'sell', 'label' => 'Sell'],
            ['key' => 'send', 'label' => 'Send'],
        ];

        $cryptoAssets = WalletCurrency::query()
            ->where('is_active', true)
            ->orderBy('currency')
            ->orderBy('blockchain')
            ->get(['currency', 'blockchain', 'blockchain_name'])
            ->map(fn ($w) => [
                'asset' => $w->currency,
                'network_key' => $w->blockchain,
                'network_label' => $w->blockchain_name ?? $w->blockchain,
            ])
            ->values()
            ->all();

        $virtualServices = [
            ['key' => 'creation', 'label' => 'Card creation'],
            ['key' => 'fund', 'label' => 'Deposit / fund card'],
            ['key' => 'withdraw', 'label' => 'Withdraw from card'],
        ];

        return ResponseHelper::success([
            'fiat' => [
                'services' => $fiatServices,
                'sub_services' => $billSubs,
            ],
            'crypto' => [
                'services' => $cryptoServices,
                'assets' => $cryptoAssets,
            ],
            'virtual_card' => [
                'services' => $virtualServices,
            ],
        ], 'Rate metadata.');
    }

    public function index(Request $request): JsonResponse
    {
        $category = (string) $request->query('category', 'fiat');
        if (! in_array($category, ['fiat', 'crypto', 'virtual_card'], true)) {
            return ResponseHelper::error('Invalid category.', 422);
        }

        $q = PlatformRate::query()->where('category', $category)->orderBy('service_key')->orderBy('id');

        if ($request->filled('search')) {
            $s = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $request->query('search')).'%';
            $q->where(function ($w) use ($s) {
                $w->where('service_key', 'like', $s)
                    ->orWhere('sub_service_key', 'like', $s)
                    ->orWhere('crypto_asset', 'like', $s)
                    ->orWhere('network_key', 'like', $s);
            });
        }

        $rows = $q->get()->map(fn (PlatformRate $r) => $this->formatRow($r));

        return ResponseHelper::success(['rates' => $rows], 'Platform rates.');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $model = new PlatformRate($data);
        $slug = PlatformRate::composeSlug($model);
        if (PlatformRate::query()->where('slug', $slug)->exists()) {
            return ResponseHelper::error('A rate with this service combination already exists.', 422);
        }
        $model->slug = $slug;
        $model->save();

        return ResponseHelper::success(['rate' => $this->formatRow($model->fresh())], 'Rate created.', 201);
    }

    public function update(Request $request, PlatformRate $platformRate): JsonResponse
    {
        $data = $this->validated($request, isUpdate: true);
        $platformRate->fill($data);
        $platformRate->slug = PlatformRate::composeSlug($platformRate);
        if (PlatformRate::query()->where('slug', $platformRate->slug)->where('id', '!=', $platformRate->id)->exists()) {
            return ResponseHelper::error('A rate with this service combination already exists.', 422);
        }
        $platformRate->save();

        return ResponseHelper::success(['rate' => $this->formatRow($platformRate->fresh())], 'Rate updated.');
    }

    public function destroy(PlatformRate $platformRate): JsonResponse
    {
        $platformRate->delete();

        return ResponseHelper::success(null, 'Rate deleted.');
    }

    public function bulkDestroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:platform_rates,id',
        ]);
        PlatformRate::query()->whereIn('id', $data['ids'])->delete();

        return ResponseHelper::success(null, 'Rates deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, bool $isUpdate = false): array
    {
        $rules = [
            'category' => $isUpdate ? ['sometimes', Rule::in(['fiat', 'crypto', 'virtual_card'])] : ['required', Rule::in(['fiat', 'crypto', 'virtual_card'])],
            'service_key' => $isUpdate ? ['sometimes', 'string', 'max:64'] : ['required', 'string', 'max:64'],
            'sub_service_key' => ['nullable', 'string', 'max:64'],
            'crypto_asset' => ['nullable', 'string', 'max:32'],
            'network_key' => ['nullable', 'string', 'max:64'],
            'exchange_rate_ngn_per_usd' => ['nullable', 'numeric', 'min:0'],
            'fixed_fee_ngn' => ['nullable', 'numeric', 'min:0'],
            'percentage_fee' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'min_fee_ngn' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];

        return $request->validate($rules);
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatRow(PlatformRate $r): array
    {
        return [
            'id' => $r->id,
            'category' => $r->category,
            'service_key' => $r->service_key,
            'sub_service_key' => $r->sub_service_key,
            'crypto_asset' => $r->crypto_asset,
            'network_key' => $r->network_key,
            'exchange_rate_ngn_per_usd' => $r->exchange_rate_ngn_per_usd !== null ? (string) $r->exchange_rate_ngn_per_usd : null,
            'fixed_fee_ngn' => (string) $r->fixed_fee_ngn,
            'percentage_fee' => $r->percentage_fee !== null ? (string) $r->percentage_fee : null,
            'min_fee_ngn' => $r->min_fee_ngn !== null ? (string) $r->min_fee_ngn : null,
            'is_active' => (bool) $r->is_active,
            'updated_at' => $r->updated_at?->toIso8601String(),
            'created_at' => $r->created_at?->toIso8601String(),
        ];
    }
}
