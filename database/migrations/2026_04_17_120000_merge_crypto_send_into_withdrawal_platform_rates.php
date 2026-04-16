<?php

use App\Models\PlatformRate;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $sendRows = PlatformRate::query()
            ->where('category', 'crypto')
            ->where('service_key', 'send')
            ->get();

        foreach ($sendRows as $send) {
            $dup = PlatformRate::query()
                ->where('category', 'crypto')
                ->where('service_key', 'withdrawal')
                ->where('crypto_asset', $send->crypto_asset)
                ->where('network_key', $send->network_key)
                ->first();
            if ($dup) {
                $send->delete();

                continue;
            }
            $send->service_key = 'withdrawal';
            $send->save();
        }
    }

    public function down(): void
    {
        // No safe automatic rollback (withdrawal rows may have been edited).
    }
};
