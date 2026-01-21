<?php

namespace App\Services;

use App\Models\Kyc;
use App\Models\User;
use Carbon\Carbon;

class KycService
{
    /**
     * Submit or update KYC information
     */
    public function submitKyc(int $userId, array $data): array
    {
        try {
            $user = User::findOrFail($userId);

            // Validate that at least some data is provided
            if (empty($data)) {
                return [
                    'success' => false,
                    'message' => 'No KYC data provided. Please provide at least one field.',
                ];
            }

            // Prepare KYC data
            $kycData = [
                'first_name' => $data['first_name'] ?? $user->first_name,
                'last_name' => $data['last_name'] ?? $user->last_name,
                'email' => $data['email'] ?? $user->email,
                'date_of_birth' => $data['date_of_birth'] ?? null,
                'bvn_number' => $data['bvn_number'] ?? null,
                'nin_number' => $data['nin_number'] ?? null,
                'status' => 'pending',
            ];

            // Validate email format if provided
            if (isset($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                return [
                    'success' => false,
                    'message' => 'Invalid email format provided.',
                ];
            }

            // Validate date of birth if provided
            if (isset($data['date_of_birth'])) {
                try {
                    $dateOfBirth = Carbon::parse($data['date_of_birth']);
                    // Ensure date is not in the future
                    if ($dateOfBirth->isFuture()) {
                        return [
                            'success' => false,
                            'message' => 'Date of birth cannot be in the future.',
                        ];
                    }
                    // Ensure user is at least 18 years old (optional validation)
                    if ($dateOfBirth->age < 18) {
                        return [
                            'success' => false,
                            'message' => 'You must be at least 18 years old to complete KYC.',
                        ];
                    }
                } catch (\Exception $e) {
                    return [
                        'success' => false,
                        'message' => 'Invalid date of birth format. Please use YYYY-MM-DD format.',
                    ];
                }
            }

            $kyc = Kyc::updateOrCreate(
                ['user_id' => $userId],
                $kycData
            );

            return [
                'success' => true,
                'message' => 'KYC information submitted successfully',
                'kyc' => $kyc->fresh(),
            ];
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return [
                'success' => false,
                'message' => 'User not found. Please ensure you are authenticated correctly.',
            ];
        } catch (\Illuminate\Database\QueryException $e) {
            $errorCode = $e->getCode();
            $errorMessage = $e->getMessage();

            if (str_contains($errorMessage, 'Duplicate entry')) {
                return [
                    'success' => false,
                    'message' => 'KYC information already exists for this user.',
                ];
            }

            return [
                'success' => false,
                'message' => config('app.debug') 
                    ? "Database error: {$errorMessage}" 
                    : 'An error occurred while saving KYC information. Please try again.',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => config('app.debug') 
                    ? "Error: {$e->getMessage()}" 
                    : 'An error occurred while submitting KYC information. Please try again.',
            ];
        }
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
