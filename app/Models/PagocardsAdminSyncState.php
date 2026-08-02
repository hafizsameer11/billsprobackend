<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PagocardsAdminSyncState extends Model
{
    public const KEY_DECLINE_FEE_VISA = 'decline_fee_visa';

    protected $table = 'pagocards_admin_sync_state';

    protected $fillable = [
        'key',
        'last_admin_transaction_id',
        'last_polled_at',
    ];

    protected function casts(): array
    {
        return [
            'last_admin_transaction_id' => 'integer',
            'last_polled_at' => 'datetime',
        ];
    }

    public static function watermarkFor(string $key): int
    {
        $row = self::query()->firstOrCreate(
            ['key' => $key],
            ['last_admin_transaction_id' => 0]
        );

        return (int) $row->last_admin_transaction_id;
    }

    public static function advanceWatermark(string $key, int $lastId): void
    {
        $row = self::query()->firstOrCreate(
            ['key' => $key],
            ['last_admin_transaction_id' => 0]
        );

        if ($lastId > (int) $row->last_admin_transaction_id) {
            $row->update([
                'last_admin_transaction_id' => $lastId,
                'last_polled_at' => now(),
            ]);
        } else {
            $row->update(['last_polled_at' => now()]);
        }
    }
}
