<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserWallet extends Model
{
    /**
     * @var list<string>
     */
    protected $hidden = [
        'mnemonic_encrypted',
    ];

    protected $fillable = [
        'user_id',
        'blockchain',
        'mnemonic_encrypted',
        'xpub',
        'derivation_path',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cryptoDepositAddresses(): HasMany
    {
        return $this->hasMany(CryptoDepositAddress::class);
    }
}
