<?php

namespace App\Services;

use App\Models\Kyc;
use App\Models\User;

class KycService
{
    /**
     * Submit or update KYC information
     */
    public function submitKyc(int $userId, array $data): array
    {
        $user = User::findOrFail($userId);

        $kyc = Kyc::updateOrCreate(
            ['user_id' => $userId],
            [
                'first_name' => $data['first_name'] ?? $user->first_name,
                'last_name' => $data['last_name'] ?? $user->last_name,
                'email' => $data['email'] ?? $user->email,
                'date_of_birth' => $data['date_of_birth'] ?? null,
                'bvn_number' => $data['bvn_number'] ?? null,
                'nin_number' => $data['nin_number'] ?? null,
                'status' => 'pending',
            ]
        );

        return [
            'success' => true,
            'message' => 'KYC information submitted successfully',
            'kyc' => $kyc,
        ];
    }

    /**
     * Get KYC information for user
     */
    public function getKyc(int $userId): ?Kyc
    {
        return Kyc::where('user_id', $userId)->first();
    }

    /**
     * Update KYC status (admin function)
     */
    public function updateKycStatus(int $kycId, string $status, string $rejectionReason = null): array
    {
        $kyc = Kyc::findOrFail($kycId);

        $kyc->update([
            'status' => $status,
            'rejection_reason' => $rejectionReason,
        ]);

        // Update user KYC status
        if ($status === 'approved') {
            $kyc->user->update(['kyc_completed' => true]);
        } else {
            $kyc->user->update(['kyc_completed' => false]);
        }

        return [
            'success' => true,
            'message' => "KYC status updated to {$status}",
            'kyc' => $kyc->fresh(),
        ];
    }
}
