<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\KycController;
use App\Http\Controllers\Api\WalletController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DepositController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\BillPaymentController;
use App\Http\Controllers\Api\VirtualCardController;
use App\Http\Controllers\Api\WithdrawalController;
use App\Http\Controllers\Api\SupportController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\CryptoController;

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
    Route::post('/login', [AuthController::class, 'login']);
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
        Route::get('/check-pin-status', [AuthController::class, 'checkPinStatus']);
        Route::post('/verify-pin', [AuthController::class, 'verifyPin']);
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
    // WITHDRAWAL ROUTES
    // ========================================================================
    Route::prefix('withdrawal')->group(function () {
        // Bank account management
        Route::get('/bank-accounts', [WithdrawalController::class, 'getBankAccounts']);
        Route::post('/bank-accounts', [WithdrawalController::class, 'addBankAccount']);
        Route::put('/bank-accounts/{id}', [WithdrawalController::class, 'updateBankAccount']);
        Route::post('/bank-accounts/{id}/set-default', [WithdrawalController::class, 'setDefaultBankAccount']);
        Route::delete('/bank-accounts/{id}', [WithdrawalController::class, 'deleteBankAccount']);
        
        // Withdrawal operations
        Route::get('/fee', [WithdrawalController::class, 'getWithdrawalFee']);
        Route::post('/', [WithdrawalController::class, 'withdraw']);
        
        // Transaction history (kept for backward compatibility, but use /transactions/withdrawals instead)
        Route::get('/transactions', [WithdrawalController::class, 'getTransactionHistory']);
        Route::get('/transactions/{transactionId}', [WithdrawalController::class, 'getTransaction']);
    });

    // ========================================================================
    // TRANSACTION ROUTES
    // ========================================================================
    Route::prefix('transactions')->group(function () {
        // Get all transactions
        Route::get('/', [TransactionController::class, 'index']);
        Route::get('/all', [TransactionController::class, 'getAllTransactions']);
        
        // Get specific transaction types
        Route::get('/bill-payments', [TransactionController::class, 'getBillPaymentTransactions']);
        Route::get('/withdrawals', [TransactionController::class, 'getWithdrawalTransactions']);
        Route::get('/deposits', [TransactionController::class, 'getDepositTransactions']);
        
        // Other transaction routes
        Route::get('/fiat', [TransactionController::class, 'getFiatTransactions']);
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
    // BILL PAYMENT ROUTES
    // ========================================================================
    Route::prefix('bill-payment')->group(function () {
        // Categories and Providers
        Route::get('/categories', [BillPaymentController::class, 'getCategories']);
        Route::get('/providers', [BillPaymentController::class, 'getProviders']);
        Route::get('/plans', [BillPaymentController::class, 'getPlans']);
        
        // Validation
        Route::post('/validate-meter', [BillPaymentController::class, 'validateMeter']);
        Route::post('/validate-account', [BillPaymentController::class, 'validateAccount']);
        
        // Payment Flow
        Route::post('/preview', [BillPaymentController::class, 'preview']);
        Route::post('/initiate', [BillPaymentController::class, 'initiate']);
        Route::post('/confirm', [BillPaymentController::class, 'confirm']);
        
        // Beneficiaries
        Route::get('/beneficiaries', [BillPaymentController::class, 'getBeneficiaries']);
        Route::post('/beneficiaries', [BillPaymentController::class, 'createBeneficiary']);
        Route::put('/beneficiaries/{id}', [BillPaymentController::class, 'updateBeneficiary']);
        Route::delete('/beneficiaries/{id}', [BillPaymentController::class, 'deleteBeneficiary']);
    });

    // ========================================================================
    // CRYPTO ROUTES
    // ========================================================================
    Route::prefix('crypto')->group(function () {
        // USDT Blockchains
        Route::get('/usdt/blockchains', [CryptoController::class, 'getUsdtBlockchains']);
        
        // Virtual Accounts
        Route::get('/accounts', [CryptoController::class, 'getAccounts']);
        Route::get('/accounts/{currency}', [CryptoController::class, 'getAccountDetails']);
        
        // Deposit Address
        Route::get('/deposit-address', [CryptoController::class, 'getDepositAddress']);
        
        // Exchange Rate
        Route::get('/exchange-rate', [CryptoController::class, 'getExchangeRate']);
        
        // Buy Crypto
        Route::post('/buy/preview', [CryptoController::class, 'previewBuyCrypto']);
        Route::post('/buy/confirm', [CryptoController::class, 'confirmBuyCrypto']);
        
        // Sell Crypto
        Route::post('/sell/preview', [CryptoController::class, 'previewSellCrypto']);
        Route::post('/sell/confirm', [CryptoController::class, 'confirmSellCrypto']);
        
        // Send Crypto (Withdrawal)
        Route::post('/send', [CryptoController::class, 'sendCrypto']);
    });

    // ========================================================================
    // VIRTUAL CARD ROUTES
    // ========================================================================
    Route::prefix('virtual-cards')->group(function () {
        Route::get('/', [VirtualCardController::class, 'index']);
        Route::post('/', [VirtualCardController::class, 'create']);
        Route::get('/{id}', [VirtualCardController::class, 'show']);
        Route::post('/{id}/fund', [VirtualCardController::class, 'fund']);
        Route::post('/{id}/withdraw', [VirtualCardController::class, 'withdraw']);
        Route::get('/{id}/transactions', [VirtualCardController::class, 'transactions']);
        Route::get('/{id}/billing-address', [VirtualCardController::class, 'getBillingAddress']);
        Route::put('/{id}/billing-address', [VirtualCardController::class, 'updateBillingAddress']);
        Route::get('/{id}/limits', [VirtualCardController::class, 'getLimits']);
        Route::put('/{id}/limits', [VirtualCardController::class, 'updateLimits']);
        Route::post('/{id}/freeze', [VirtualCardController::class, 'freeze']);
        Route::post('/{id}/unfreeze', [VirtualCardController::class, 'unfreeze']);
    });

    // ========================================================================
    // SUPPORT ROUTES
    // ========================================================================
    Route::prefix('support')->group(function () {
        Route::get('/', [SupportController::class, 'index']);
        Route::get('/tickets', [SupportController::class, 'getTickets']);
        Route::post('/tickets', [SupportController::class, 'createTicket']);
        Route::get('/tickets/{id}', [SupportController::class, 'getTicket']);
        Route::put('/tickets/{id}', [SupportController::class, 'updateTicket']);
        Route::post('/tickets/{id}/close', [SupportController::class, 'closeTicket']);
    });

    // ========================================================================
    // CHAT ROUTES
    // ========================================================================
    Route::prefix('chat')->group(function () {
        Route::get('/session', [ChatController::class, 'getActiveSession']);
        Route::get('/sessions', [ChatController::class, 'getSessions']);
        Route::post('/start', [ChatController::class, 'startChat']);
        Route::get('/sessions/{id}', [ChatController::class, 'getSession']);
        Route::post('/sessions/{id}/messages', [ChatController::class, 'sendMessage']);
        Route::get('/sessions/{id}/messages', [ChatController::class, 'getMessages']);
        Route::post('/sessions/{id}/read', [ChatController::class, 'markAsRead']);
        Route::post('/sessions/{id}/close', [ChatController::class, 'closeSession']);
    });

    // ========================================================================
    // USER ROUTES
    // ========================================================================
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
});
