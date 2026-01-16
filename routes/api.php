<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\KycController;
use App\Http\Controllers\Api\WalletController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DepositController;
use App\Http\Controllers\Api\TransactionController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// ============================================================================
// PUBLIC ROUTES - Authentication
// ============================================================================
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/verify-email-otp', [AuthController::class, 'verifyEmailOtp']);
    Route::post('/resend-otp', [AuthController::class, 'resendOtp']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/verify-password-reset-otp', [AuthController::class, 'verifyPasswordResetOtp']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
});

// ============================================================================
// PROTECTED ROUTES - Require Authentication
// ============================================================================
Route::middleware('auth:sanctum')->group(function () {
    
    // ========================================================================
    // AUTHENTICATION ROUTES
    // ========================================================================
    Route::prefix('auth')->group(function () {
        Route::post('/set-pin', [AuthController::class, 'setPin']);
    });

    // ========================================================================
    // DASHBOARD ROUTES
    // ========================================================================
    Route::prefix('dashboard')->group(function () {
        Route::get('/', [DashboardController::class, 'index']);
    });

    // ========================================================================
    // WALLET ROUTES
    // ========================================================================
    Route::prefix('wallet')->group(function () {
        Route::get('/balance', [WalletController::class, 'getBalance']);
        Route::get('/fiat', [WalletController::class, 'getFiatWallets']);
        Route::get('/crypto', [WalletController::class, 'getCryptoWallets']);
    });

    // ========================================================================
    // DEPOSIT ROUTES
    // ========================================================================
    Route::prefix('deposit')->group(function () {
        Route::get('/bank-account', [DepositController::class, 'getBankAccount']);
        Route::post('/initiate', [DepositController::class, 'initiate']);
        Route::post('/confirm', [DepositController::class, 'confirm']);
        Route::get('/history', [DepositController::class, 'history']);
        Route::get('/{reference}', [DepositController::class, 'show']);
    });

    // ========================================================================
    // TRANSACTION ROUTES
    // ========================================================================
    Route::prefix('transactions')->group(function () {
        Route::get('/', [TransactionController::class, 'index']);
        Route::get('/stats', [TransactionController::class, 'stats']);
        Route::get('/{transactionId}', [TransactionController::class, 'show']);
    });

    // ========================================================================
    // KYC ROUTES
    // ========================================================================
    Route::prefix('kyc')->group(function () {
        Route::post('/', [KycController::class, 'submit']);
        Route::get('/', [KycController::class, 'get']);
    });

    // ========================================================================
    // USER ROUTES
    // ========================================================================
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
});
