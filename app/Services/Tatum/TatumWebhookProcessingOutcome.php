<?php

namespace App\Services\Tatum;

final class TatumWebhookProcessingOutcome
{
    public const CREDITED = 'credited';

    public const DUPLICATE_TX = 'duplicate_tx';

    public const IGNORED_NON_DEPOSIT = 'ignored_non_deposit';

    public const IGNORED_MASTER_WALLET = 'ignored_master_wallet';

    public const IGNORED_UNLISTED_ASSET = 'ignored_unlisted_asset';

    public const IGNORED_UNKNOWN_ADDRESS = 'ignored_unknown_address';

    public const IGNORED_NO_SENDER = 'ignored_no_sender';

    public const FAILED_UNSUPPORTED_TYPE = 'failed_unsupported_type';

    public const FAILED_MISSING_TX = 'failed_missing_tx';

    public const FAILED_MISSING_ADDRESS = 'failed_missing_address';

    public const FAILED_ZERO_AMOUNT = 'failed_zero_amount';

    /**
     * Outcomes that mean processing finished intentionally (no retry needed).
     *
     * @return list<string>
     */
    public static function terminal(): array
    {
        return [
            self::CREDITED,
            self::DUPLICATE_TX,
            self::IGNORED_NON_DEPOSIT,
            self::IGNORED_MASTER_WALLET,
            self::IGNORED_UNLISTED_ASSET,
            self::IGNORED_UNKNOWN_ADDRESS,
            self::IGNORED_NO_SENDER,
            self::FAILED_UNSUPPORTED_TYPE,
            self::FAILED_MISSING_TX,
            self::FAILED_MISSING_ADDRESS,
            self::FAILED_ZERO_AMOUNT,
        ];
    }

    public static function isTerminal(?string $outcome): bool
    {
        return $outcome !== null && in_array($outcome, self::terminal(), true);
    }
}
