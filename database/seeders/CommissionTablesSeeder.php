<?php

namespace Database\Seeders;

use App\Models\BillCommissionRate;
use App\Models\CommissionVolumeTier;
use Illuminate\Database\Seeder;

class CommissionTablesSeeder extends Seeder
{
    public function run(): void
    {
        $tiers = [
            ['tier_key' => '1', 'label' => 'Tier 1', 'min_monthly_volume_ngn' => 0, 'max_monthly_volume_ngn' => 50_000_000, 'sort_order' => 1],
            ['tier_key' => '2', 'label' => 'Tier 2', 'min_monthly_volume_ngn' => 50_000_000, 'max_monthly_volume_ngn' => 200_000_000, 'sort_order' => 2],
            ['tier_key' => '3', 'label' => 'Tier 3', 'min_monthly_volume_ngn' => 200_000_000, 'max_monthly_volume_ngn' => null, 'sort_order' => 3],
        ];

        foreach ($tiers as $t) {
            CommissionVolumeTier::query()->updateOrCreate(
                ['tier_key' => $t['tier_key']],
                $t
            );
        }

        $airtimeNetworks = [
            'MTN' => [['1', 1.90], ['2', 2.38], ['3', 2.85]],
            'Airtel' => [['1', 1.90], ['2', 2.38], ['3', 2.85]],
            'Glo' => [['1', 2.85], ['2', 3.33], ['3', 4.28]],
            '9Mobile' => [['1', 2.85], ['2', 3.80], ['3', 4.75]],
        ];

        foreach ($airtimeNetworks as $network => $tierRows) {
            foreach ($tierRows as [$tierKey, $pct]) {
                foreach (['airtime', 'data'] as $scene) {
                    BillCommissionRate::query()->updateOrCreate(
                        [
                            'scene' => $scene,
                            'entity_key' => $network,
                            'tier_key' => $tierKey,
                        ],
                        ['commission_pct' => $pct, 'is_active' => true]
                    );
                }
            }
        }

        $betting = [
            'NaijaBet' => 1.43,
            'BetWinner' => 1.43,
            'BetKing' => 0.67,
            '1xBet' => 0.67,
            'Bet9ja' => 0.48,
            'Bangbet' => 1.71,
            'Waje Game' => 1.43,
            'Africa 365' => 1.71,
            'Nairabet' => 0.67,
            'Betbaba' => 1.43,
            'Betano' => 1.43,
            'iLOTbet' => 1.43,
            'BetGr8' => 1.71,
            'Paripesa' => 1.33,
            'Easywin' => 1.43,
            'BetWay' => 0.57,
            'BetCorrect' => 0.67,
            'AccessBet' => 0.95,
            'Surebet247' => 1.33,
        ];

        foreach ($betting as $platform => $pct) {
            BillCommissionRate::query()->updateOrCreate(
                [
                    'scene' => 'betting',
                    'entity_key' => $platform,
                    'tier_key' => '1',
                ],
                ['commission_pct' => $pct, 'is_active' => true]
            );
        }
    }
}
