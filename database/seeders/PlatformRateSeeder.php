<?php

namespace Database\Seeders;

use App\Models\BillPaymentCategory;
use App\Models\PlatformRate;
use App\Models\WalletCurrency;
use App\Services\Crypto\CryptoService;
use Illuminate\Database\Seeder;

/**
 * Seeds `platform_rates` for every fee surface exposed in the admin Rates UI:
 * fiat (deposit, withdrawal, bill payment + per-category overrides), crypto
 * (deposit / send / buy / sell — global fallbacks plus one row per active wallet pair),
 * and virtual card (creation, fund, withdraw).
 *
 * Run after `BillPaymentCategorySeeder` and `WalletCurrencySeeder` when you want
 * per-category bill fees and per-asset crypto rows populated automatically.
 */
class PlatformRateSeeder extends Seeder
{
    public function run(): void
    {
        $usdToNgn = (float) config('virtual_card.usd_to_ngn_rate', 1500.0);
        $creationFeeUsd = (float) config('virtual_card.creation_fee_usd', 3.0);
        $fundFlatUsd = (float) config('virtual_card.fund_load_flat_fee_usd', 1.0);
        $fundPct = (float) config('virtual_card.fund_load_percent', 1.0);

        $sendFeeUsd = CryptoService::SEND_FEE_USD;

        $billPct = 1.0;
        $billMinNgn = 20.0;
        $billFixedNgn = 0.0;

        $rows = [
            // --- Fiat ---
            [
                'category' => 'fiat',
                'service_key' => 'deposit',
                'sub_service_key' => null,
                'crypto_asset' => null,
                'network_key' => null,
                'exchange_rate_ngn_per_usd' => null,
                'fixed_fee_ngn' => 200,
                'percentage_fee' => null,
                'min_fee_ngn' => null,
                'fee_usd' => null,
            ],
            [
                'category' => 'fiat',
                'service_key' => 'withdrawal',
                'sub_service_key' => null,
                'crypto_asset' => null,
                'network_key' => null,
                'exchange_rate_ngn_per_usd' => null,
                'fixed_fee_ngn' => 200,
                'percentage_fee' => null,
                'min_fee_ngn' => null,
                'fee_usd' => null,
            ],
            [
                'category' => 'fiat',
                'service_key' => 'bill_payment',
                'sub_service_key' => null,
                'crypto_asset' => null,
                'network_key' => null,
                'exchange_rate_ngn_per_usd' => null,
                'fixed_fee_ngn' => $billFixedNgn,
                'percentage_fee' => $billPct,
                'min_fee_ngn' => $billMinNgn,
                'fee_usd' => null,
            ],
            // --- Crypto: global fallbacks (resolver matches asset-specific rows first when present) ---
            [
                'category' => 'crypto',
                'service_key' => 'deposit',
                'sub_service_key' => null,
                'crypto_asset' => null,
                'network_key' => null,
                'exchange_rate_ngn_per_usd' => null,
                'fixed_fee_ngn' => 0,
                'percentage_fee' => null,
                'min_fee_ngn' => null,
                'fee_usd' => 0,
            ],
            [
                'category' => 'crypto',
                'service_key' => 'withdrawal',
                'sub_service_key' => null,
                'crypto_asset' => null,
                'network_key' => null,
                'exchange_rate_ngn_per_usd' => null,
                'fixed_fee_ngn' => 0,
                'percentage_fee' => null,
                'min_fee_ngn' => null,
                'fee_usd' => $sendFeeUsd,
            ],
            [
                'category' => 'crypto',
                'service_key' => 'buy',
                'sub_service_key' => null,
                'crypto_asset' => null,
                'network_key' => null,
                'exchange_rate_ngn_per_usd' => null,
                'fixed_fee_ngn' => 0,
                'percentage_fee' => null,
                'min_fee_ngn' => null,
                'fee_usd' => null,
            ],
            [
                'category' => 'crypto',
                'service_key' => 'sell',
                'sub_service_key' => null,
                'crypto_asset' => null,
                'network_key' => null,
                'exchange_rate_ngn_per_usd' => null,
                'fixed_fee_ngn' => 0,
                'percentage_fee' => null,
                'min_fee_ngn' => null,
                'fee_usd' => null,
            ],
            // --- Virtual card ---
            [
                'category' => 'virtual_card',
                'service_key' => 'creation',
                'sub_service_key' => null,
                'crypto_asset' => null,
                'network_key' => null,
                'exchange_rate_ngn_per_usd' => $usdToNgn,
                'fixed_fee_ngn' => 0,
                'percentage_fee' => null,
                'min_fee_ngn' => null,
                'fee_usd' => $creationFeeUsd,
            ],
            [
                'category' => 'virtual_card',
                'service_key' => 'fund',
                'sub_service_key' => null,
                'crypto_asset' => null,
                'network_key' => null,
                'exchange_rate_ngn_per_usd' => $usdToNgn,
                'fixed_fee_ngn' => 0,
                'percentage_fee' => $fundPct,
                'min_fee_ngn' => null,
                'fee_usd' => $fundFlatUsd,
            ],
            [
                'category' => 'virtual_card',
                'service_key' => 'withdraw',
                'sub_service_key' => null,
                'crypto_asset' => null,
                'network_key' => null,
                'exchange_rate_ngn_per_usd' => $usdToNgn,
                'fixed_fee_ngn' => 0,
                'percentage_fee' => null,
                'min_fee_ngn' => null,
                'fee_usd' => null,
            ],
        ];

        foreach (BillPaymentCategory::query()->where('is_active', true)->orderBy('code')->cursor() as $cat) {
            $code = (string) $cat->code;
            if ($code === '') {
                continue;
            }
            $rows[] = [
                'category' => 'fiat',
                'service_key' => 'bill_payment',
                'sub_service_key' => $code,
                'crypto_asset' => null,
                'network_key' => null,
                'exchange_rate_ngn_per_usd' => null,
                'fixed_fee_ngn' => $billFixedNgn,
                'percentage_fee' => $billPct,
                'min_fee_ngn' => $billMinNgn,
                'fee_usd' => null,
            ];
        }

        foreach (WalletCurrency::query()->where('is_active', true)->orderBy('currency')->orderBy('blockchain')->cursor() as $wc) {
            $asset = (string) $wc->currency;
            $network = (string) $wc->blockchain;
            if ($asset === '' || $network === '') {
                continue;
            }
            $rows[] = [
                'category' => 'crypto',
                'service_key' => 'deposit',
                'sub_service_key' => null,
                'crypto_asset' => $asset,
                'network_key' => $network,
                'exchange_rate_ngn_per_usd' => null,
                'fixed_fee_ngn' => 0,
                'percentage_fee' => null,
                'min_fee_ngn' => null,
                'fee_usd' => 0,
            ];
            $rows[] = [
                'category' => 'crypto',
                'service_key' => 'withdrawal',
                'sub_service_key' => null,
                'crypto_asset' => $asset,
                'network_key' => $network,
                'exchange_rate_ngn_per_usd' => null,
                'fixed_fee_ngn' => 0,
                'percentage_fee' => null,
                'min_fee_ngn' => null,
                'fee_usd' => $sendFeeUsd,
            ];
            $rows[] = [
                'category' => 'crypto',
                'service_key' => 'buy',
                'sub_service_key' => null,
                'crypto_asset' => $asset,
                'network_key' => $network,
                'exchange_rate_ngn_per_usd' => null,
                'fixed_fee_ngn' => 0,
                'percentage_fee' => null,
                'min_fee_ngn' => null,
                'fee_usd' => null,
            ];
            $rows[] = [
                'category' => 'crypto',
                'service_key' => 'sell',
                'sub_service_key' => null,
                'crypto_asset' => $asset,
                'network_key' => $network,
                'exchange_rate_ngn_per_usd' => null,
                'fixed_fee_ngn' => 0,
                'percentage_fee' => null,
                'min_fee_ngn' => null,
                'fee_usd' => null,
            ];
        }

        foreach ($rows as $data) {
            $m = new PlatformRate($data);
            $slug = PlatformRate::composeSlug($m);
            PlatformRate::query()->updateOrCreate(
                ['slug' => $slug],
                array_merge($data, ['slug' => $slug, 'is_active' => true])
            );
        }
    }
}
