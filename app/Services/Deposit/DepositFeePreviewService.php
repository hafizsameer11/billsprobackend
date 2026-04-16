<?php

namespace App\Services\Deposit;

use App\Services\Platform\PlatformRateResolver;
use App\Support\PalmPayConfig;

/**
 * NGN deposit fees: `fee_ngn` is the ledger line on the deposit record; `display_fee_ngn` is the
 * admin-configured fiat deposit rate (platform_rates) always shown in the app fee row.
 */
class DepositFeePreviewService
{
    public function __construct(
        protected PlatformRateResolver $platformRates
    ) {}

    public function displayFiatDepositFeeNgn(float $fallback = 200.0): float
    {
        return $this->platformRates->fiatDepositFeeNgn($fallback);
    }

    /**
     * @return array{fee_ngn: float, display_fee_ngn: float, currency: string, uses_palmpay: bool}
     */
    public function quoteForAuthenticatedDepositFlow(): array
    {
        $display = $this->displayFiatDepositFeeNgn(200.0);

        if (PalmPayConfig::usePalmPayForFiatDeposit()) {
            return [
                'fee_ngn' => 0.0,
                'display_fee_ngn' => $display,
                'currency' => 'NGN',
                'uses_palmpay' => true,
            ];
        }

        return [
            'fee_ngn' => $display,
            'display_fee_ngn' => $display,
            'currency' => 'NGN',
            'uses_palmpay' => false,
        ];
    }
}
