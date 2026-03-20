<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MasterWalletSecret extends Model
{
    protected $fillable = [
        'master_wallet_id',
        'mnemonic_encrypted',
        'xpub_encrypted',
        'private_key_encrypted',
    ];

    public function masterWallet(): BelongsTo
    {
        return $this->belongsTo(MasterWallet::class);
    }
}
