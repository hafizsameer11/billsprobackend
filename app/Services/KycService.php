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

            $existing = Kyc::where('user_id', $userId)->first();
            $needsIdentityVerification = $this->shouldVerifyIdentity($existing, $nin, $bvn);

            $kycData = [
                'first_name' => $data['first_name'] ?? $user->first_name,
                'last_name' => $data['last_name'] ?? $user->last_name,
                'email' => $data['email'] ?? $user->email,
                'date_of_birth' => $data['date_of_birth'] ?? null,
                'bvn_number' => $bvn,
                'nin_number' => $nin,
                'location' => $data['location'] ?? null,
                'status' => 'pending',
            ];

            // Only call CheckMyNinBvn when NIN/BVN are new or changed (avoids repeat API cost).
            if ($needsIdentityVerification) {
                $identity = $this->verifyIdentityWithProvider($nin, $bvn, $existing);
                if (!$identity['success']) {
                    return [
                        'success' => false,
                        'message' => $identity['message'],
                    ];
                }

                if (array_key_exists('nin', $identity) && !empty($identity['nin'])) {
                    $kycData['nin_verification_report_id'] = $identity['nin']['report_id'] ?? null;
                    $kycData['nin_verification_status'] = ($identity['nin']['success'] ?? false) ? 'success' : 'failed';
                    $kycData['nin_verification_data'] = $identity['nin']['data'] ?? null;
                }
                if (array_key_exists('bvn', $identity) && !empty($identity['bvn'])) {
                    $kycData['bvn_verification_report_id'] = $identity['bvn']['report_id'] ?? null;
                    $kycData['bvn_verification_status'] = ($identity['bvn']['success'] ?? false) ? 'success' : 'failed';
                    $kycData['bvn_verification_data'] = $identity['bvn']['data'] ?? null;
                }
                $kycData['identity_verified_at'] = now();
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
     * Call the provider only on first successful verify, or when NIN/BVN change.
     */
    protected function shouldVerifyIdentity(?Kyc $existing, string $nin, string $bvn): bool
    {
        if (!$existing) {
            return true;
        }

        $sameNin = trim((string) $existing->nin_number) === $nin;
        $sameBvn = trim((string) $existing->bvn_number) === $bvn;
        $alreadyVerified =
            strtolower((string) $existing->nin_verification_status) === 'success'
            && strtolower((string) $existing->bvn_verification_status) === 'success';

        // Reuse stored verification when numbers are unchanged and already verified.
        if ($sameNin && $sameBvn && $alreadyVerified) {
            return false;
        }

        return true;
    }

    /**
     * Verify NIN + BVN with CheckMyNinBvn.
     * Reuses a previously successful result when that number did not change.
     *
     * @return array{success: bool, message: string, nin: array, bvn: array}
     */
    protected function verifyIdentityWithProvider(string $nin, string $bvn, ?Kyc $existing = null): array
    {
        $client = CheckMyNinBvnClient::fromConfig();

        $sameNin = $existing && trim((string) $existing->nin_number) === $nin;
        $sameBvn = $existing && trim((string) $existing->bvn_number) === $bvn;
        $ninOk = $sameNin && strtolower((string) $existing->nin_verification_status) === 'success';
        $bvnOk = $sameBvn && strtolower((string) $existing->bvn_verification_status) === 'success';

        $ninResult = $ninOk
            ? [
                'success' => true,
                'report_id' => $existing->nin_verification_report_id,
                'message' => 'Reused previous NIN verification',
                'data' => $existing->nin_verification_data,
            ]
            : $client->verifyNin($nin);

        if (!$ninResult['success']) {
            return [
                'success' => false,
                'message' => $this->friendlyIdentityErrorMessage('NIN', (string) ($ninResult['message'] ?? '')),
                'nin' => $ninResult,
                'bvn' => [],
            ];
        }

        $bvnResult = $bvnOk
            ? [
                'success' => true,
                'report_id' => $existing->bvn_verification_report_id,
                'message' => 'Reused previous BVN verification',
                'data' => $existing->bvn_verification_data,
            ]
            : $client->verifyBvn($bvn);

        if (!$bvnResult['success']) {
            return [
                'success' => false,
                'message' => $this->friendlyIdentityErrorMessage('BVN', (string) ($bvnResult['message'] ?? '')),
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
     * Map provider errors (e.g. "No record found/Invalid Input") to clear user-facing copy.
     */
    protected function friendlyIdentityErrorMessage(string $field, string $providerMessage): string
    {
        $lower = strtolower($providerMessage);

        $looksInvalid =
            $providerMessage === ''
            || str_contains($lower, 'no record')
            || str_contains($lower, 'invalid')
            || str_contains($lower, 'not found')
            || str_contains($lower, 'incorrect')
            || str_contains($lower, 'does not match')
            || str_contains($lower, 'failed');

        if ($looksInvalid) {
            return "Your {$field} is not correct. Please check the number and try again.";
        }

        // Keep rare provider/system messages (e.g. balance/timeout) readable.
        return $providerMessage;
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
