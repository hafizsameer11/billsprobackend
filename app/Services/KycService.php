<?php

namespace App\Services;

use App\Models\Kyc;
use App\Models\User;
use App\Services\CheckMyNinBvn\CheckMyNinBvnClient;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class KycService
{
    /**
     * Submit or update KYC information
     */
    public function submitKyc(int $userId, array $data): array
    {
        try {
            $user = User::findOrFail($userId);

            if (empty($data)) {
                return [
                    'success' => false,
                    'message' => 'No KYC data provided. Please provide at least one field.',
                ];
            }

            $nin = trim((string) ($data['nin_number'] ?? ''));
            $bvn = trim((string) ($data['bvn_number'] ?? ''));

            if ($nin === '' || $bvn === '') {
                return [
                    'success' => false,
                    'message' => 'NIN and BVN are required for identity verification.',
                ];
            }

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
                    if ($dateOfBirth->isFuture()) {
                        return [
                            'success' => false,
                            'message' => 'Date of birth cannot be in the future.',
                        ];
                    }
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

            $identity = $this->verifyIdentityWithProvider($nin, $bvn);
            if (!$identity['success']) {
                return [
                    'success' => false,
                    'message' => $identity['message'],
                ];
            }

            $kycData = [
                'first_name' => $data['first_name'] ?? $user->first_name,
                'last_name' => $data['last_name'] ?? $user->last_name,
                'email' => $data['email'] ?? $user->email,
                'date_of_birth' => $data['date_of_birth'] ?? null,
                'bvn_number' => $bvn,
                'nin_number' => $nin,
                'location' => $data['location'] ?? null,
                'nin_verification_report_id' => $identity['nin']['report_id'] ?? null,
                'bvn_verification_report_id' => $identity['bvn']['report_id'] ?? null,
                'nin_verification_status' => ($identity['nin']['success'] ?? false) ? 'success' : 'failed',
                'bvn_verification_status' => ($identity['bvn']['success'] ?? false) ? 'success' : 'failed',
                'nin_verification_data' => $identity['nin']['data'] ?? null,
                'bvn_verification_data' => $identity['bvn']['data'] ?? null,
                'identity_verified_at' => now(),
                'status' => 'pending',
            ];

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
        } catch (\RuntimeException $e) {
            Log::error('KYC identity provider config error: '.$e->getMessage());

            return [
                'success' => false,
                'message' => 'Identity verification is temporarily unavailable. Please try again later.',
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
     * Verify NIN + BVN with CheckMyNinBvn.
     *
     * @return array{success: bool, message: string, nin: array, bvn: array}
     */
    protected function verifyIdentityWithProvider(string $nin, string $bvn): array
    {
        $client = CheckMyNinBvnClient::fromConfig();

        $ninResult = $client->verifyNin($nin);
        if (!$ninResult['success']) {
            return [
                'success' => false,
                'message' => $ninResult['message'] ?: 'NIN verification failed. Please check the NIN and try again.',
                'nin' => $ninResult,
                'bvn' => [],
            ];
        }

        $bvnResult = $client->verifyBvn($bvn);
        if (!$bvnResult['success']) {
            return [
                'success' => false,
                'message' => $bvnResult['message'] ?: 'BVN verification failed. Please check the BVN and try again.',
                'nin' => $ninResult,
                'bvn' => $bvnResult,
            ];
        }

        return [
            'success' => true,
            'message' => 'Identity verified successfully',
            'nin' => $ninResult,
            'bvn' => $bvnResult,
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
     * Upload face verification video for KYC
     */
    public function uploadFaceVerificationVideo(int $userId, UploadedFile $file): array
    {
        try {
            $kyc = Kyc::where('user_id', $userId)->first();

            if (!$kyc) {
                return [
                    'success' => false,
                    'message' => 'Please submit your KYC information before face verification.',
                ];
            }

            $disk = 'local';

            if ($kyc->face_verification_video_path) {
                Storage::disk($kyc->face_verification_video_disk ?? $disk)
                    ->delete($kyc->face_verification_video_path);
            }

            $path = $file->store("kyc/{$userId}/face-verification", $disk);

            $kyc->update([
                'face_verification_video_path' => $path,
                'face_verification_video_disk' => $disk,
                'face_verification_submitted_at' => now(),
                'status' => 'pending',
            ]);

            return [
                'success' => true,
                'message' => 'Face verification video uploaded successfully',
                'kyc' => $kyc->fresh(),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => config('app.debug')
                    ? "Error: {$e->getMessage()}"
                    : 'An error occurred while uploading face verification video. Please try again.',
            ];
        }
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
