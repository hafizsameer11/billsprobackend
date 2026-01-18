<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\VerifyEmailOtpRequest;
use App\Http\Requests\Auth\ResendOtpRequest;
use App\Http\Requests\Auth\SetPinRequest;
use App\Http\Requests\Auth\VerifyPinRequest;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\VerifyPasswordResetOtpRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Services\Auth\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

class AuthController extends Controller
{
    protected AuthService $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * Register a new user
     */
    #[OA\Post(path: "/api/auth/register", summary: "Register a new user", tags: ["Authentication"])]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(required: ["first_name", "last_name", "email", "password"], properties: [new OA\Property(property: "first_name", type: "string", example: "John"), new OA\Property(property: "last_name", type: "string", example: "Doe"), new OA\Property(property: "email", type: "string", format: "email", example: "john.doe@example.com"), new OA\Property(property: "phone_number", type: "string", nullable: true, example: "08012345678"), new OA\Property(property: "password", type: "string", format: "password", example: "password123"), new OA\Property(property: "country_code", type: "string", nullable: true, example: "NG")]))]
    #[OA\Response(response: 201, description: "User registered successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "message", type: "string", example: "Registration successful. Please verify your email."), new OA\Property(property: "data", type: "object")]))]
    #[OA\Response(response: 400, description: "Bad request - User already exists")]
    #[OA\Response(response: 422, description: "Validation error")]
    public function register(RegisterRequest $request): JsonResponse
    {
        try {
            $result = $this->authService->register($request->validated());

            if (!$result['success']) {
                return ResponseHelper::error($result['message'] ?? 'Registration failed', 400);
            }

            return ResponseHelper::success($result, $result['message'] ?? 'Registration successful. Please verify your email.', 201);
        } catch (\Exception $e) {
            Log::error('Registration error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred during registration. Please try again.');
        }
    }

    /**
     * Verify email OTP and create wallets
     */
    #[OA\Post(path: "/api/auth/verify-email-otp", summary: "Verify email OTP and create wallets", description: "Verifies the OTP sent to user's email. Upon successful verification, automatically creates fiat wallet (NGN for Nigeria) and crypto virtual accounts for all supported currencies. Returns authentication token for the user to proceed with KYC.", tags: ["Authentication"])]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(required: ["email", "otp"], properties: [new OA\Property(property: "email", type: "string", format: "email", example: "john.doe@example.com"), new OA\Property(property: "otp", type: "string", example: "12345")]))]
    #[OA\Response(response: 200, description: "Email verified successfully and wallets created", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "message", type: "string", example: "Email verified successfully. Wallets created."), new OA\Property(property: "data", type: "object", properties: [new OA\Property(property: "user", type: "object"), new OA\Property(property: "token", type: "string", example: "1|xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx")])]))]
    #[OA\Response(response: 400, description: "Invalid or expired OTP")]
    #[OA\Response(response: 422, description: "Validation error")]
    public function verifyEmailOtp(VerifyEmailOtpRequest $request): JsonResponse
    {
        try {
            $result = $this->authService->verifyEmailOtp($request->email, $request->otp);

            if (!$result['success']) {
                return ResponseHelper::error($result['message'] ?? 'OTP verification failed', 400);
            }

            // Return user and token in response
            return ResponseHelper::success([
                'user' => $result['user'],
                'token' => $result['token'],
            ], $result['message'] ?? 'Email verified successfully. Wallets created.');
        } catch (\Exception $e) {
            Log::error('OTP verification error: ' . $e->getMessage(), [
                'email' => $request->email,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred during OTP verification. Please try again.');
        }
    }

    /**
     * Resend OTP to email or phone
     */
    #[OA\Post(path: "/api/auth/resend-otp", summary: "Resend OTP to email or phone", tags: ["Authentication"])]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(required: ["type"], properties: [new OA\Property(property: "email", type: "string", format: "email", nullable: true, example: "john.doe@example.com"), new OA\Property(property: "phone_number", type: "string", nullable: true, example: "08012345678"), new OA\Property(property: "type", type: "string", enum: ["email", "phone"], example: "email")]))]
    #[OA\Response(response: 200, description: "OTP sent successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "message", type: "string", example: "OTP sent to your email"), new OA\Property(property: "data", type: "object")]))]
    #[OA\Response(response: 422, description: "Validation error")]
    public function resendOtp(ResendOtpRequest $request): JsonResponse
    {
        try {
            $result = $this->authService->resendOtp(
                $request->email,
                $request->phone_number,
                $request->type
            );

            return ResponseHelper::success($result, $result['message'] ?? 'OTP sent successfully.');
        } catch (\Exception $e) {
            Log::error('Resend OTP error: ' . $e->getMessage(), [
                'type' => $request->type,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while sending OTP. Please try again.');
        }
    }

    /**
     * Set 4-digit PIN for transactions
     */
    #[OA\Post(path: "/api/auth/set-pin", summary: "Set 4-digit PIN for transactions", security: [["sanctum" => []]], tags: ["Authentication"])]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(required: ["pin"], properties: [new OA\Property(property: "pin", type: "string", example: "1234")]))]
    #[OA\Response(response: 200, description: "PIN set successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "message", type: "string", example: "Your transaction pin has been set successfully")]))]
    #[OA\Response(response: 400, description: "Invalid PIN format")]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    #[OA\Response(response: 422, description: "Validation error")]
    public function setPin(SetPinRequest $request): JsonResponse
    {
        try {
            $result = $this->authService->setPin($request->user(), $request->pin);

            if (!$result['success']) {
                return ResponseHelper::error($result['message'] ?? 'PIN setup failed', 400);
            }

            return ResponseHelper::success(null, $result['message'] ?? 'Your transaction pin has been set successfully.');
        } catch (\Exception $e) {
            Log::error('Set PIN error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while setting PIN. Please try again.');
        }
    }

    /**
     * Check if user has set up transaction PIN
     */
    #[OA\Get(path: "/api/auth/check-pin-status", summary: "Check if user has set up transaction PIN", security: [["sanctum" => []]], tags: ["Authentication"])]
    #[OA\Response(response: 200, description: "PIN status retrieved successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "message", type: "string", example: "PIN is set"), new OA\Property(property: "data", type: "object")]))]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    public function checkPinStatus(\Illuminate\Http\Request $request): JsonResponse
    {
        try {
            $result = $this->authService->checkPinStatus($request->user());

            return ResponseHelper::success(['pin_set' => $result['pin_set']], $result['message']);
        } catch (\Exception $e) {
            Log::error('Check PIN status error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while checking PIN status. Please try again.');
        }
    }

    /**
     * Verify transaction PIN
     */
    #[OA\Post(path: "/api/auth/verify-pin", summary: "Verify transaction PIN", security: [["sanctum" => []]], tags: ["Authentication"])]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(required: ["pin"], properties: [new OA\Property(property: "pin", type: "string", example: "1234")]))]
    #[OA\Response(response: 200, description: "PIN verified successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "message", type: "string", example: "PIN verified successfully")]))]
    #[OA\Response(response: 400, description: "Invalid PIN or PIN not set")]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    #[OA\Response(response: 422, description: "Validation error")]
    public function verifyPin(VerifyPinRequest $request): JsonResponse
    {
        try {
            $user = $request->user();
            $isValid = $this->authService->verifyPin($user, $request->pin);

            if (!$isValid) {
                if (!$user->pin) {
                    return ResponseHelper::error('PIN not set. Please setup your PIN first.', 400);
                }
                return ResponseHelper::error('Invalid PIN', 400);
            }

            return ResponseHelper::success(null, 'PIN verified successfully');
        } catch (\Exception $e) {
            Log::error('Verify PIN error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while verifying PIN. Please try again.');
        }
    }

    /**
     * Request password reset (send OTP)
     */
    #[OA\Post(path: "/api/auth/forgot-password", summary: "Request password reset", description: "Send a 5-digit OTP to the user's registered email address for password reset.", tags: ["Authentication"])]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(required: ["email"], properties: [new OA\Property(property: "email", type: "string", format: "email", example: "john.doe@example.com", description: "Registered email address")]))]
    #[OA\Response(response: 200, description: "Password reset OTP sent successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "message", type: "string", example: "Password reset OTP sent to your email"), new OA\Property(property: "data", type: "object")]))]
    #[OA\Response(response: 400, description: "User not found")]
    #[OA\Response(response: 422, description: "Validation error")]
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        try {
            $result = $this->authService->forgotPassword($request->email);

            if (!$result['success']) {
                return ResponseHelper::error($result['message'] ?? 'Password reset request failed', 400);
            }

            return ResponseHelper::success($result, $result['message'] ?? 'Password reset OTP sent to your email.');
        } catch (\Exception $e) {
            Log::error('Forgot password error: ' . $e->getMessage(), [
                'email' => $request->email,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while processing password reset request. Please try again.');
        }
    }

    /**
     * Verify password reset OTP
     */
    #[OA\Post(path: "/api/auth/verify-password-reset-otp", summary: "Verify password reset OTP", description: "Verify the 5-digit OTP sent to user's email for password reset.", tags: ["Authentication"])]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(required: ["email", "otp"], properties: [new OA\Property(property: "email", type: "string", format: "email", example: "john.doe@example.com"), new OA\Property(property: "otp", type: "string", example: "12345", description: "5-digit OTP code")]))]
    #[OA\Response(response: 200, description: "OTP verified successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "message", type: "string", example: "OTP verified successfully. You can now reset your password.")]))]
    #[OA\Response(response: 400, description: "Invalid or expired OTP")]
    #[OA\Response(response: 422, description: "Validation error")]
    public function verifyPasswordResetOtp(VerifyPasswordResetOtpRequest $request): JsonResponse
    {
        try {
            $result = $this->authService->verifyPasswordResetOtp($request->email, $request->otp);

            if (!$result['success']) {
                return ResponseHelper::error($result['message'] ?? 'OTP verification failed', 400);
            }

            return ResponseHelper::success(null, $result['message'] ?? 'OTP verified successfully. You can now reset your password.');
        } catch (\Exception $e) {
            Log::error('Verify password reset OTP error: ' . $e->getMessage(), [
                'email' => $request->email,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred during OTP verification. Please try again.');
        }
    }

    /**
     * Reset password
     */
    #[OA\Post(path: "/api/auth/reset-password", summary: "Reset password", description: "Reset user password with verified OTP. Requires email, OTP, new password, and password confirmation.", tags: ["Authentication"])]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(required: ["email", "otp", "password", "password_confirmation"], properties: [new OA\Property(property: "email", type: "string", format: "email", example: "john.doe@example.com"), new OA\Property(property: "otp", type: "string", example: "12345", description: "5-digit OTP code"), new OA\Property(property: "password", type: "string", format: "password", example: "newpassword123", description: "New password (minimum 8 characters)"), new OA\Property(property: "password_confirmation", type: "string", format: "password", example: "newpassword123", description: "Confirm new password")]))]
    #[OA\Response(response: 200, description: "Password reset successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "message", type: "string", example: "Password reset successfully. You can now login with your new password.")]))]
    #[OA\Response(response: 400, description: "Invalid or expired OTP")]
    #[OA\Response(response: 422, description: "Validation error")]
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        try {
            $result = $this->authService->resetPassword(
                $request->email,
                $request->otp,
                $request->password
            );

            if (!$result['success']) {
                return ResponseHelper::error($result['message'] ?? 'Password reset failed', 400);
            }

            return ResponseHelper::success(null, $result['message'] ?? 'Password reset successfully. You can now login with your new password.');
        } catch (\Exception $e) {
            Log::error('Reset password error: ' . $e->getMessage(), [
                'email' => $request->email,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while resetting password. Please try again.');
        }
    }

    /**
     * Login user
     */
    #[OA\Post(path: "/api/auth/login", summary: "Login user", description: "Authenticate user and return access token.", tags: ["Authentication"])]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(required: ["email", "password"], properties: [new OA\Property(property: "email", type: "string", format: "email", example: "john.doe@example.com"), new OA\Property(property: "password", type: "string", format: "password", example: "password123")]))]
    #[OA\Response(response: 200, description: "Login successful", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "message", type: "string", example: "Login successful"), new OA\Property(property: "data", type: "object", properties: [new OA\Property(property: "user", type: "object"), new OA\Property(property: "token", type: "string", example: "1|xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx")])]))]
    #[OA\Response(response: 400, description: "Invalid credentials or email not verified")]
    #[OA\Response(response: 422, description: "Validation error")]
    public function login(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|email',
                'password' => 'required|string|min:6',
            ]);

            if ($validator->fails()) {
                return ResponseHelper::validationError($validator->errors());
            }

            $result = $this->authService->login($request->email, $request->password);

            if (!$result['success']) {
                return ResponseHelper::error($result['message'] ?? 'Login failed', 400);
            }

            return ResponseHelper::success([
                'user' => $result['user'],
                'token' => $result['token'],
            ], $result['message'] ?? 'Login successful.');
        } catch (\Exception $e) {
            Log::error('Login error: ' . $e->getMessage(), [
                'email' => $request->email,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred during login. Please try again.');
        }
    }
}
