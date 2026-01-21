<?php

namespace App\Services\Auth;

use App\Helpers\NotificationHelper;
use App\Models\User;
use App\Services\Wallet\WalletService;
use App\Services\Crypto\CryptoWalletService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class AuthService
{
    protected OtpService $otpService;
    protected WalletService $walletService;
    protected CryptoWalletService $cryptoWalletService;

    public function __construct(
        OtpService $otpService,
        WalletService $walletService,
        CryptoWalletService $cryptoWalletService
    ) {
        $this->otpService = $otpService;
        $this->walletService = $walletService;
        $this->cryptoWalletService = $cryptoWalletService;
    }

    /**
     * Register a new user
     */
    public function register(array $data): array
    {
        // Check if user already exists
        if (User::where('email', $data['email'])->exists()) {
            return [
                'success' => false,
                'message' => 'User with this email already exists',
            ];
        }

        if (isset($data['phone_number']) && User::where('phone_number', $data['phone_number'])->exists()) {
            return [
                'success' => false,
                'message' => 'User with this phone number already exists',
            ];
        }

        // Create user
        $user = User::create([
            'name' => trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? '')),
            'first_name' => $data['first_name'] ?? null,
            'last_name' => $data['last_name'] ?? null,
            'email' => $data['email'],
            'phone_number' => $data['phone_number'] ?? null,
            'password' => Hash::make($data['password']),
            'country_code' => $data['country_code'] ?? 'NG',
            'email_verified' => false,
            'phone_verified' => false,
            'kyc_completed' => false,
        ]);

        // Send OTP to email
        $otpResult = $this->otpService->sendOtp($user->email, null, 'email');

        return [
            'success' => true,
            'message' => 'Registration successful. Please verify your email.',
            'user' => $user,
            'otp' => $otpResult,
        ];
    }

    /**
     * Verify email OTP and create wallets
     */
    public function verifyEmailOtp(string $email, string $otp): array
    {
        $user = User::where('email', $email)->first();

        if (!$user) {
            return [
                'success' => false,
                'message' => 'User not found',
            ];
        }

        if ($user->email_verified) {
            return [
                'success' => false,
                'message' => 'Email already verified',
            ];
        }

        // Verify OTP
        $otpResult = $this->otpService->verifyOtp($otp, $email, null, 'email');

        if (!$otpResult['success']) {
            return $otpResult;
        }

        // Mark email as verified
        $user->update(['email_verified' => true]);

        // Create wallets in a transaction
        DB::transaction(function () use ($user) {
            // Create fiat wallet (NGN for Nigeria only)
            if ($user->country_code === 'NG') {
                $this->walletService->createFiatWallet($user->id, 'NGN', 'NG');
            }

            // Create crypto wallets (virtual accounts)
            $this->cryptoWalletService->initializeUserCryptoWallets($user->id);
        });

        // Generate authentication token for the user
        $token = $user->createToken('auth-token')->plainTextToken;

        return [
            'success' => true,
            'message' => 'Email verified successfully. Wallets created.',
            'user' => $user->fresh(),
            'token' => $token,
        ];
    }

    /**
     * Resend OTP
     */
    public function resendOtp(string $email = null, string $phoneNumber = null, string $type = 'email'): array
    {
        return $this->otpService->sendOtp($email, $phoneNumber, $type);
    }

    /**
     * Set PIN
     */
    public function setPin(User $user, string $pin): array
    {
        if (strlen($pin) !== 4 || !ctype_digit($pin)) {
            return [
                'success' => false,
                'message' => 'PIN must be 4 digits',
            ];
        }

        $user->update(['pin' => Hash::make($pin)]);

        return [
            'success' => true,
            'message' => 'Your transaction pin has been set successfully',
        ];
    }

    /**
     * Check if user has set up PIN
     */
    public function checkPinStatus(User $user): array
    {
        return [
            'success' => true,
            'pin_set' => !empty($user->pin),
            'message' => $user->pin ? 'PIN is set' : 'PIN is not set',
        ];
    }

    /**
     * Verify PIN
     */
    public function verifyPin(User $user, string $pin): bool
    {
        if (!$user->pin) {
            return false;
        }

        return Hash::check($pin, $user->pin);
    }

    /**
     * Request password reset (send OTP)
     */
    public function forgotPassword(string $email): array
    {
        $user = User::where('email', $email)->first();

        if (!$user) {
            return [
                'success' => false,
                'message' => 'No account found with this email address',
            ];
        }

        // Send OTP to email (for password reset)
        $otpResult = $this->otpService->sendOtp($email, null, 'email', 'password_reset');

        return [
            'success' => true,
            'message' => 'Password reset OTP sent to your email',
            'expires_at' => $otpResult['expires_at'] ?? null,
        ];
    }

    /**
     * Verify password reset OTP (check validity without marking as verified)
     */
    public function verifyPasswordResetOtp(string $email, string $otp): array
    {
        $user = User::where('email', $email)->first();

        if (!$user) {
            return [
                'success' => false,
                'message' => 'User not found',
            ];
        }

        // Check if OTP is valid (without marking as verified)
        $isValid = $this->otpService->isValidOtp($otp, $email, null, 'email');

        if (!$isValid) {
            return [
                'success' => false,
                'message' => 'Invalid or expired OTP',
            ];
        }

        return [
            'success' => true,
            'message' => 'OTP verified successfully. You can now reset your password.',
        ];
    }

    /**
     * Reset password
     */
    public function resetPassword(string $email, string $otp, string $password): array
    {
        $user = User::where('email', $email)->first();

        if (!$user) {
            return [
                'success' => false,
                'message' => 'User not found',
            ];
        }

        // Verify OTP first
        $otpResult = $this->otpService->verifyOtp($otp, $email, null, 'email');

        if (!$otpResult['success']) {
            return [
                'success' => false,
                'message' => 'Invalid or expired OTP',
            ];
        }

        // Update password
        $user->update([
            'password' => Hash::make($password),
        ]);

        return [
            'success' => true,
            'message' => 'Password reset successfully. You can now login with your new password.',
        ];
    }

    /**
     * Login user
     */
    public function login(string $email, string $password): array
    {
        $user = User::where('email', $email)->first();

        if (!$user) {
            return [
                'success' => false,
                'message' => 'Invalid credentials',
            ];
        }

        // Check password
        if (!Hash::check($password, $user->password)) {
            return [
                'success' => false,
                'message' => 'Invalid credentials',
            ];
        }

        // Check if email is verified
        if (!$user->email_verified) {
            return [
                'success' => false,
                'message' => 'Please verify your email before logging in',
            ];
        }

        // Create token
        $token = $user->createToken('auth-token')->plainTextToken;

        // Create login notification
        try {
            NotificationHelper::createLoginNotification(
                $user,
                request()->ip(),
                request()->userAgent()
            );
        } catch (\Exception $e) {
            // Log error but don't fail login if notification creation fails
            \Log::error('Failed to create login notification: ' . $e->getMessage());
        }

        return [
            'success' => true,
            'message' => 'Login successful',
            'user' => $user,
            'token' => $token,
        ];
    }
}
