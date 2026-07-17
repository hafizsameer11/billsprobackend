<?php

namespace App\Services\Auth;

use App\Models\OtpVerification;
use App\Models\User;
use App\Mail\OtpVerificationMail;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class OtpService
{
    /**
     * Generate a 5-digit OTP
     */
    public function generateOtp(): string
    {
        return str_pad((string) random_int(10000, 99999), 5, '0', STR_PAD_LEFT);
    }

    /**
     * Send OTP to email or phone
     */
    public function sendOtp(string $email = null, string $phoneNumber = null, string $type = 'email', string $emailType = 'email'): array
    {
        $otp = $this->generateOtp();
        $expiresAt = Carbon::now()->addMinutes(5);

        // Invalidate previous OTPs
        if ($type === 'email' && $email) {
            OtpVerification::where('email', $email)
                ->where('type', 'email')
                ->where('verified', false)
                ->update(['verified' => true]);
        } elseif ($type === 'phone' && $phoneNumber) {
            OtpVerification::where('phone_number', $phoneNumber)
                ->where('type', 'phone')
                ->where('verified', false)
                ->update(['verified' => true]);
        }

        // Create new OTP record
        $otpVerification = OtpVerification::create([
            'email' => $email,
            'phone_number' => $phoneNumber,
            'otp' => $otp,
            'type' => $type,
            'verified' => false,
            'expires_at' => $expiresAt,
        ]);

        // Send OTP via email or SMS.
        // Email is dispatched after the HTTP response so signup/login never hangs
        // on slow SMTP / unreachable mailboxes ("loads forever" on the app).
        try {
            if ($type === 'email' && $email) {
                $mailEmail = $email;
                $mailOtp = $otp;
                $mailType = $emailType;

                dispatch(function () use ($mailEmail, $mailOtp, $mailType) {
                    try {
                        Mail::to($mailEmail)->send(new OtpVerificationMail($mailOtp, $mailType, 5));
                        Log::info("OTP email sent successfully to: {$mailEmail}", [
                            'email_type' => $mailType,
                        ]);
                    } catch (\Throwable $e) {
                        Log::error('Failed to send OTP email after response: '.$e->getMessage(), [
                            'email' => $mailEmail,
                            'email_type' => $mailType,
                            'trace' => $e->getTraceAsString(),
                        ]);
                    }
                })->afterResponse();
            } elseif ($type === 'phone' && $phoneNumber) {
                // TODO: Integrate with SMS service (Twilio, etc.)
                Log::info("OTP SMS should be sent to: {$phoneNumber} (SMS service not integrated yet)");
            }
        } catch (\Exception $e) {
            Log::error("Failed to queue OTP send: " . $e->getMessage(), [
                'email' => $email,
                'phone' => $phoneNumber,
                'type' => $type,
                'email_type' => $emailType,
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return [
            'success' => true,
            'message' => "OTP sent to your {$type}",
            'expires_at' => $expiresAt->toDateTimeString(),
        ];
    }

    /**
     * Verify OTP
     */
    public function verifyOtp(string $otp, string $email = null, string $phoneNumber = null, string $type = 'email'): array
    {
        $baseQuery = OtpVerification::query()
            ->where('type', $type)
            ->where('verified', false)
            ->where('expires_at', '>', Carbon::now());

        if ($type === 'email' && $email) {
            $baseQuery->where('email', $email);
        } elseif ($type === 'phone' && $phoneNumber) {
            $baseQuery->where('phone_number', $phoneNumber);
        }

        $latest = (clone $baseQuery)->orderByDesc('id')->first();

        if (! $latest) {
            return [
                'success' => false,
                'message' => 'Invalid or expired OTP',
            ];
        }

        if ((int) $latest->failed_attempts >= 5) {
            $latest->update(['verified' => true]);

            return [
                'success' => false,
                'message' => 'Too many failed attempts. Request a new OTP.',
            ];
        }

        if (! hash_equals((string) $latest->otp, $otp)) {
            $latest->increment('failed_attempts');

            return [
                'success' => false,
                'message' => 'Invalid or expired OTP',
            ];
        }

        $latest->update(['verified' => true]);

        return [
            'success' => true,
            'message' => 'OTP verified successfully',
        ];
    }

    /**
     * Check if OTP is valid (not expired and not used)
     */
    public function isValidOtp(string $otp, string $email = null, string $phoneNumber = null, string $type = 'email'): bool
    {
        $query = OtpVerification::where('otp', $otp)
            ->where('type', $type)
            ->where('verified', false)
            ->where('expires_at', '>', Carbon::now());

        if ($type === 'email' && $email) {
            $query->where('email', $email);
        } elseif ($type === 'phone' && $phoneNumber) {
            $query->where('phone_number', $phoneNumber);
        }

        return $query->exists();
    }
}
