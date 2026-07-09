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
        'location',
        'nin_verification_report_id',
        'bvn_verification_report_id',
        'nin_verification_status',
        'bvn_verification_status',
        'nin_verification_data',
        'bvn_verification_data',
        'identity_verified_at',
        'face_verification_video_path',
        'face_verification_video_disk',
        'face_verification_submitted_at',
        'status',
        'rejection_reason',
    ];

    protected $appends = [
        'has_face_verification_video',
        'has_identity_verification',
    ];

    protected $hidden = [
        'face_verification_video_path',
        'face_verification_video_disk',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'face_verification_submitted_at' => 'datetime',
            'identity_verified_at' => 'datetime',
            'nin_verification_data' => 'array',
            'bvn_verification_data' => 'array',
        ];
    }

    public function getHasFaceVerificationVideoAttribute(): bool
    {
        return !empty($this->face_verification_video_path);
    }

    public function getHasIdentityVerificationAttribute(): bool
    {
        return !empty($this->nin_verification_data) || !empty($this->bvn_verification_data);
    }

    /**
     * Get the user that owns the KYC.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
