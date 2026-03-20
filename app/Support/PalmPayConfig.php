<?php

namespace App\Support;

/**
 * Central flags for when PalmPay replaces legacy merchant-bank / mock bill flows.
 */
class PalmPayConfig
{
    public static function usePalmPayForFiatDeposit(): bool
    {
        if (filter_var(config('palmpay.legacy_manual_fiat_deposit', false), FILTER_VALIDATE_BOOL)) {
            return false;
        }

        if (! filter_var(config('palmpay.use_for_fiat_deposit', true), FILTER_VALIDATE_BOOL)) {
            return false;
        }

        return self::hasCheckoutCredentials();
    }

    /**
     * PalmPay checkout deposit requires VA + webhook.
     */
    public static function hasCheckoutCredentials(): bool
    {
        return filled(config('palmpay.app_id'))
            && filled(config('palmpay.private_key'))
            && filled(config('palmpay.webhook_url'));
    }

    /**
     * When true, legacy /api/bill-payment preview|initiate|confirm|validate-* are disabled
     * if PalmPay bill API credentials are present (use /api/bill-payment/palmpay/*).
     */
    public static function usePalmPayForBillPayment(): bool
    {
        if (! filter_var(config('palmpay.use_for_bill_payment', true), FILTER_VALIDATE_BOOL)) {
            return false;
        }

        return self::hasBillApiCredentials();
    }

    public static function hasBillApiCredentials(): bool
    {
        return filled(config('palmpay.app_id')) && filled(config('palmpay.private_key'));
    }

    /**
     * PalmPay payout uses the same signing + webhook URL as checkout.
     */
    public static function usePalmPayForWithdrawal(): bool
    {
        if (filter_var(config('palmpay.legacy_internal_withdrawal', false), FILTER_VALIDATE_BOOL)) {
            return false;
        }

        if (! filter_var(config('palmpay.use_for_withdrawal', true), FILTER_VALIDATE_BOOL)) {
            return false;
        }

        return self::hasCheckoutCredentials();
    }
}
