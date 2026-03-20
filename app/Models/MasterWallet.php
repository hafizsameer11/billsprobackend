<?php

namespace App\Models;

use App\Services\Crypto\KeyEncryptionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use RuntimeException;

class MasterWallet extends Model
{
    protected $fillable = [
        'blockchain',
        'address',
        'label',
    ];

    public function secret(): HasOne
    {
        return $this->hasOne(MasterWalletSecret::class);
    }

    public function masterWalletTransactions(): HasMany
    {
        return $this->hasMany(MasterWalletTransaction::class);
    }

    /**
     * Decrypted signing material for Tatum outbound transactions.
     */
    public function decryptedPrivateKey(KeyEncryptionService $encryption): string
    {
        $secret = $this->relationLoaded('secret') ? $this->secret : $this->secret()->first();
        if (! $secret || empty($secret->private_key_encrypted)) {
            throw new RuntimeException('Master wallet has no encrypted private key (run crypto:generate-master-wallet).');
        }

        return $encryption->decrypt($secret->private_key_encrypted);
    }

    public function decryptedMnemonic(KeyEncryptionService $encryption): ?string
    {
        $secret = $this->relationLoaded('secret') ? $this->secret : $this->secret()->first();
        if (! $secret || empty($secret->mnemonic_encrypted)) {
            return null;
        }

        return $encryption->decrypt($secret->mnemonic_encrypted);
    }
}
