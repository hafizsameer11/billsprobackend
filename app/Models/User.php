<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'first_name',
        'last_name',
        'email',
        'phone_number',
        'password',
        'pin',
        'email_verified',
        'phone_verified',
        'kyc_completed',
        'country_code',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'pin' => 'hashed',
            'email_verified' => 'boolean',
            'phone_verified' => 'boolean',
            'kyc_completed' => 'boolean',
        ];
    }

    /**
     * Get the KYC record for the user.
     */
    public function kyc()
    {
        return $this->hasOne(Kyc::class);
    }

    /**
     * Get the fiat wallets for the user.
     */
    public function fiatWallets()
    {
        return $this->hasMany(FiatWallet::class);
    }

    /**
     * Get the virtual accounts for the user.
     */
    public function virtualAccounts()
    {
        return $this->hasMany(VirtualAccount::class);
    }

    /**
     * Get the bank accounts for the user.
     */
    public function bankAccounts()
    {
        return $this->hasMany(BankAccount::class);
    }

    /**
     * Get the transactions for the user.
     */
    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }
}
