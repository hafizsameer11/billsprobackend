<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CryptoDepositAddress extends Model
{
    /**
     * @var list<string>
     */
    protected $hidden = [
        'private_key_encrypted',
    ];

    protected $fillable = [
        'virtual_account_id',
        'user_wallet_id',
        'blockchain',
        'currency',
        'address',
        'index',
        'private_key_encrypted',
    ];

    protected function casts(): array
    {
        return [
            'index' => 'integer',
        ];
    }

    public function virtualAccount(): BelongsTo
    {
        return $this->belongsTo(VirtualAccount::class);
    }

    public function userWallet(): BelongsTo
    {
        return $this->belongsTo(UserWallet::class);
    }
}
