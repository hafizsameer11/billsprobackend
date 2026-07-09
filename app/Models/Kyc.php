<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Kyc extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'kyc';

    protected $fillable = [
        'user_id',
        'first_name',
        'last_name',
        'email',
        'date_of_birth',
        'bvn_number',
        'nin_number',
        'face_verification_video_path',
        'face_verification_video_disk',
        'face_verification_submitted_at',
        'status',
        'rejection_reason',
    ];

    protected $appends = [
        'has_face_verification_video',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'face_verification_submitted_at' => 'datetime',
        ];
    }

    public function getHasFaceVerificationVideoAttribute(): bool
    {
        return !empty($this->face_verification_video_path);
    }

    /**
     * Get the user that owns the KYC.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
