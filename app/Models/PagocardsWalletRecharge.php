<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PagocardsWalletRecharge extends Model
{
    protected $fillable = [
        'ngn_spent',
        'usd_gross',
        'usd_credited',
        'true_rate_ngn_per_usd',
        'recharged_at',
        'notes',
        'created_by',
        'applied_to_history_at',
        'history_backfill_count',
        'db_backup_path',
    ];

    protected function casts(): array
    {
        return [
            'ngn_spent' => 'decimal:2',
            'usd_gross' => 'decimal:4',
            'usd_credited' => 'decimal:4',
            'true_rate_ngn_per_usd' => 'decimal:8',
            'recharged_at' => 'datetime',
            'applied_to_history_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
