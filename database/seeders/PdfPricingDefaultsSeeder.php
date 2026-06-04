<?php

namespace Database\Seeders;

use App\Models\PlatformRate;
use App\Models\WalletCurrency;
use Illuminate\Database\Seeder;

/**
 * Applies PDF default provider costs and customer charges on top of PlatformRateSeeder rows.
 */
class PdfPricingDefaultsSeeder extends Seeder
{
    public function run(): void
    {
        $this->patchRate('fiat', 'withdrawal', null, null, null, [
            'display_label' => 'Bank Transfer',
            'fixed_fee_ngn' => 50,
            'provider_cost_ngn' => 25,
        ]);

        $this->patchRate('fiat', 'deposit', null, null, null, [
            'display_label' => 'Wallet Deposit (PalmPay)',
            'fixed_fee_ngn' => 0,
            'percentage_fee' => 0,
            'provider_pct' => 0.7,
            'provider_pct_cap_ngn' => 700,
        ]);

        $this->patchRate('virtual_card', 'creation', null, null, null, [
            'display_label' => 'Mastercard Card Issuance',
            'fee_usd' => 3,
            'provider_cost_usd' => 1.5,
        ]);

        $this->patchRate('virtual_card', 'fund', null, null, null, [
            'display_label' => 'Mastercard Card Funding',
            'fee_usd' => 1,
            'percentage_fee' => 1,
            'provider_cost_usd' => 1,
            'provider_pct' => 1,
        ]);

        $this->patchRate('virtual_card', 'visa_creation', null, null, null, [
            'display_label' => 'Visa Card Issuance',
            'fee_usd' => 6,
            'provider_cost_usd' => 4,
        ]);

        $this->patchRate('virtual_card', 'visa_fund', null, null, null, [
            'display_label' => 'Visa Card Funding',
            'fee_usd' => 1,
            'percentage_fee' => 2,
            'provider_cost_usd' => 1,
            'provider_pct' => 2,
        ]);

        $this->ensureDeclineRates();

        $cryptoReceive = [
            'display_label' => 'Crypto Receive',
            'fee_usd' => 1,
            'provider_cost_usd' => 0,
        ];
        $this->patchRate('crypto', 'deposit', null, null, null, $cryptoReceive);

        $sendFees = [
            'SOL' => ['SOL', 0.10, 0.01],
            'BTC' => ['BTC', 2.0, 1.0],
            'BSC' => ['BSC', 0.10, 0.05],
            'DOGE' => ['DOGE', 0.50, 0.15],
            'ETH' => ['ETH', 1.0, 0.50],
            'TRX' => ['TRX', 1.0, 0.0],
            'USDT' => ['TRON', 1.0, 0.0],
        ];

        foreach (WalletCurrency::query()->where('is_active', true)->cursor() as $wc) {
            $asset = strtoupper((string) $wc->currency);
            $network = strtoupper((string) $wc->blockchain);
            $match = $sendFees[$asset] ?? null;
            if ($match && strtoupper($match[0]) !== $network && $asset === 'USDT') {
                if ($network !== 'TRON' && $network !== 'ETH') {
                    continue;
                }
            }
            if (! $match) {
                if ($asset === 'USDT' && $network === 'ETH') {
                    $charge = 1.5;
                    $cost = 0.5;
                } else {
                    continue;
                }
            } else {
                $charge = $match[1];
                $cost = $match[2];
                if ($asset === 'USDT' && $network === 'ETH') {
                    $charge = 1.5;
                    $cost = 0.5;
                }
            }

            $label = "{$asset} Send ({$network})";
            $this->patchRate('crypto', 'withdrawal', null, $asset, $network, [
                'display_label' => $label,
                'fee_usd' => $charge,
                'provider_cost_usd' => $cost,
            ]);
        }
    }

    protected function ensureDeclineRates(): void
    {
        foreach ([
            ['creation', 'decline_fee', 'Mastercard Decline Fee', 1, 0],
            ['visa_creation', 'visa_decline_fee', 'Visa Decline Fee', 1, 0.75],
        ] as [$baseKey, $declineKey, $label, $chargeUsd, $costUsd]) {
            $base = PlatformRate::query()
                ->where('category', 'virtual_card')
                ->where('service_key', $declineKey)
                ->first();
            if ($base) {
                $base->update([
                    'display_label' => $label,
                    'fee_usd' => $chargeUsd,
                    'provider_cost_usd' => $costUsd,
                ]);

                continue;
            }

            $m = new PlatformRate([
                'category' => 'virtual_card',
                'service_key' => $declineKey,
                'fee_usd' => $chargeUsd,
                'provider_cost_usd' => $costUsd,
                'display_label' => $label,
                'is_active' => true,
            ]);
            $m->slug = PlatformRate::composeSlug($m);
            $m->save();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function patchRate(
        string $category,
        string $serviceKey,
        ?string $sub,
        ?string $asset,
        ?string $network,
        array $data
    ): void {
        $q = PlatformRate::query()
            ->where('category', $category)
            ->where('service_key', $serviceKey);

        if ($sub !== null) {
            $q->where('sub_service_key', $sub);
        } else {
            $q->whereNull('sub_service_key');
        }
        if ($asset !== null) {
            $q->where('crypto_asset', $asset);
        } else {
            $q->whereNull('crypto_asset');
        }
        if ($network !== null) {
            $q->where('network_key', $network);
        } else {
            $q->whereNull('network_key');
        }

        $row = $q->first();
        if ($row) {
            $row->update($data);
        }
    }
}
