<?php

use App\Http\Controllers\Api\AdminAdjustmentController;
use App\Http\Controllers\Api\AdminBillPaymentController;
use App\Http\Controllers\Api\AdminCryptoExtensionController;
use App\Http\Controllers\Api\AdminCryptoTreasuryController;
use App\Http\Controllers\Api\AdminCryptoVendorController;
use App\Http\Controllers\Api\AdminDepositController;
use App\Http\Controllers\Api\AdminFiatWalletController;
use App\Http\Controllers\Api\AdminKycController;
use App\Http\Controllers\Api\AdminMasterWalletController;
use App\Http\Controllers\Api\AdminNotificationController;
use App\Http\Controllers\Api\AdminPlatformRateController;
use App\Http\Controllers\Api\AdminProfitController;
use App\Http\Controllers\Api\AdminStatsController;
use App\Http\Controllers\Api\AdminSupportTicketController;
use App\Http\Controllers\Api\AdminTransactionController;
use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\AdminVirtualAccountController;
use App\Http\Controllers\Api\AdminVirtualCardController;
use App\Http\Controllers\Api\AdminWalletCurrencyController;
use App\Http\Controllers\Api\AdminWalletUsersController;
use App\Http\Controllers\Api\AdminWebhookController;
use App\Http\Controllers\Api\AdminWithdrawalController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillPaymentController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\CryptoController;
use App\Http\Controllers\Api\CryptoReceivedAssetSyncController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DepositController;
use App\Http\Controllers\Api\KycController;
use App\Http\Controllers\Api\PalmPayBillPaymentController;
use App\Http\Controllers\Api\PalmPayDepositController;
use App\Http\Controllers\Api\PagocardsVirtualCardWebhookController;
use App\Http\Controllers\Api\PalmPayWebhookController;
use App\Http\Controllers\Api\SupportController;
use App\Http\Controllers\Api\TatumWebhookController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\PushTokenController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VirtualCardController;
use App\Http\Controllers\Api\WalletController;
use App\Http\Controllers\Api\WithdrawalController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

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
// PALMPAY WEBHOOKS (public — verified via signature)
// ============================================================================
Route::post('/webhooks/palmpay', [PalmPayWebhookController::class, 'handle']);
Route::post('/webhooks/palmpay/bill-payment', [PalmPayWebhookController::class, 'handle']);
Route::post('/webhooks/palmpay/replay-pending', [PalmPayWebhookController::class, 'replayPending']);
Route::get('/webhooks/palmpay/replay-pending', [PalmPayWebhookController::class, 'replayPending']);
Route::post('/webhooks/tatum', [TatumWebhookController::class, 'handle']);
Route::post('/webhooks/pagocards/virtual-cards', [PagocardsVirtualCardWebhookController::class, 'handle']);
Route::get('/webhooks/tatum/replay/{id}', [TatumWebhookController::class, 'replay']);
Route::get('/webhooks/tatum/replay-pending', [TatumWebhookController::class, 'replayPending']);

/** Backfill received_assets from an existing crypto_deposit transaction (no auth — protect at reverse proxy if needed). */
Route::get('/crypto/sync-received-asset', [CryptoReceivedAssetSyncController::class, 'syncFromTransaction']);

// ============================================================================
// PROTECTED ROUTES - Require Authentication
// ============================================================================
Route::middleware(['auth:sanctum', 'account.active'])->group(function () {

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
        Route::get('/fee', [DepositController::class, 'fee']);
        Route::get('/bank-account', [DepositController::class, 'getBankAccount']);
        Route::post('/initiate', [DepositController::class, 'initiate']);
        Route::post('/confirm', [DepositController::class, 'confirm']);
        Route::get('/history', [DepositController::class, 'history']);
        Route::prefix('palmpay')->group(function () {
            Route::post('/initiate', [PalmPayDepositController::class, 'initiate']);
            Route::get('/status/{depositReference}', [PalmPayDepositController::class, 'status']);
        });
        Route::get('/{reference}', [DepositController::class, 'show']);
    });

    Route::prefix('bill-payment/palmpay')->group(function () {
        Route::get('/billers', [PalmPayBillPaymentController::class, 'billers']);
        Route::get('/items', [PalmPayBillPaymentController::class, 'items']);
        Route::post('/verify-account', [PalmPayBillPaymentController::class, 'verifyAccount']);
        Route::post('/create-order', [PalmPayBillPaymentController::class, 'createOrder']);
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
        Route::get('/palmpay/banks', [WithdrawalController::class, 'getPalmPayBanks']);
        Route::post('/palmpay/verify-account', [WithdrawalController::class, 'verifyPalmPayAccount']);
        Route::post('/palmpay/initiate', [WithdrawalController::class, 'initiatePalmPayWithdrawal']);
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
    // ADMIN — full operator API (requires is_admin)
    // ========================================================================
    Route::middleware(['admin'])->prefix('admin')->group(function () {
        Route::get('/stats', [AdminStatsController::class, 'index']);

        Route::get('/wallet-users', [AdminWalletUsersController::class, 'index']);
        Route::get('/wallet-users/totals', [AdminWalletUsersController::class, 'totals']);

        Route::get('/users', [AdminUserController::class, 'index']);
        Route::post('/users/admin-create', [AdminUserController::class, 'storeAdmin']);
        Route::get('/users/{user}', [AdminUserController::class, 'show']);
        Route::patch('/users/{user}', [AdminUserController::class, 'update']);
        Route::delete('/users/{user}', [AdminUserController::class, 'destroy']);
        Route::post('/users/{user}/suspend', [AdminUserController::class, 'suspend']);
        Route::post('/users/{user}/unsuspend', [AdminUserController::class, 'unsuspend']);
        Route::post('/users/{user}/ban', [AdminUserController::class, 'ban']);
        Route::post('/users/{user}/tokens/revoke', [AdminUserController::class, 'revokeTokens']);
        Route::post('/users/{user}/password/reset', [AdminUserController::class, 'resetPassword']);
        Route::get('/users/{user}/timeline', [AdminUserController::class, 'timeline']);
        Route::get('/users/{user}/audit-logs', [AdminUserController::class, 'auditLogs']);

        Route::get('/users/{user}/fiat-wallets', [AdminFiatWalletController::class, 'forUser']);
        Route::patch('/fiat-wallets/{fiatWallet}', [AdminFiatWalletController::class, 'update']);

        Route::get('/users/{user}/virtual-accounts', [AdminVirtualAccountController::class, 'forUser']);
        Route::patch('/virtual-accounts/{virtualAccount}', [AdminVirtualAccountController::class, 'update']);

        Route::get('/users/{user}/deposit-addresses', [AdminCryptoExtensionController::class, 'userDepositAddresses']);

        Route::get('/transactions', [AdminTransactionController::class, 'index']);
        Route::get('/transactions/{transactionId}', [AdminTransactionController::class, 'show']);

        Route::get('/profit/settings', [AdminProfitController::class, 'settings']);
        Route::put('/profit/settings/{serviceKey}', [AdminProfitController::class, 'updateSetting']);
        Route::get('/profit/transactions', [AdminProfitController::class, 'transactions']);

        Route::get('/deposits', [AdminDepositController::class, 'index']);
        Route::get('/deposit-fee-quote', [AdminDepositController::class, 'feeQuote']);
        Route::get('/deposits/{id}', [AdminDepositController::class, 'show']);

        Route::get('/withdrawals', [AdminWithdrawalController::class, 'index']);
        Route::get('/withdrawals/{transactionId}', [AdminWithdrawalController::class, 'show']);

        Route::get('/kyc', [AdminKycController::class, 'index']);
        Route::get('/kyc/{user}', [AdminKycController::class, 'show']);
        Route::post('/kyc/{user}/approve', [AdminKycController::class, 'approve']);
        Route::post('/kyc/{user}/reject', [AdminKycController::class, 'reject']);

        Route::get('/bill-payments/summary', [AdminBillPaymentController::class, 'summary']);
        Route::get('/bill-payments', [AdminBillPaymentController::class, 'index']);
        Route::get('/bill-payments/{id}', [AdminBillPaymentController::class, 'show']);

        Route::get('/master-wallet/summary', [AdminMasterWalletController::class, 'summary']);
        Route::get('/master-wallet/transactions', [AdminMasterWalletController::class, 'transactions']);

        Route::get('/platform-rates/meta', [AdminPlatformRateController::class, 'meta']);
        Route::get('/platform-rates', [AdminPlatformRateController::class, 'index']);
        Route::post('/platform-rates', [AdminPlatformRateController::class, 'store']);
        Route::put('/platform-rates/{platformRate}', [AdminPlatformRateController::class, 'update']);
        Route::delete('/platform-rates/{platformRate}', [AdminPlatformRateController::class, 'destroy']);
        Route::post('/platform-rates/bulk-delete', [AdminPlatformRateController::class, 'bulkDestroy']);

        Route::get('/users/{user}/virtual-cards', [AdminVirtualCardController::class, 'forUser']);
        Route::get('/users/{user}/virtual-card-transactions', [AdminVirtualCardController::class, 'transactionsForUser']);

        Route::get('/virtual-cards/summary', [AdminVirtualCardController::class, 'summary']);
        Route::get('/virtual-cards/users-overview', [AdminVirtualCardController::class, 'usersOverview']);

        Route::get('/virtual-cards', [AdminVirtualCardController::class, 'index']);
        Route::get('/virtual-cards/{id}', [AdminVirtualCardController::class, 'show']);
        Route::post('/virtual-cards/{id}/freeze', [AdminVirtualCardController::class, 'freeze']);
        Route::post('/virtual-cards/{id}/unfreeze', [AdminVirtualCardController::class, 'unfreeze']);
        Route::post('/virtual-cards/{id}/fund', [AdminVirtualCardController::class, 'fund']);

        Route::get('/support/tickets/summary', [AdminSupportTicketController::class, 'summary']);
        Route::get('/support/tickets', [AdminSupportTicketController::class, 'index']);
        Route::get('/support/tickets/{supportTicket}', [AdminSupportTicketController::class, 'show']);
        Route::post('/support/tickets/{supportTicket}/messages', [AdminSupportTicketController::class, 'storeMessage']);
        Route::patch('/support/tickets/{supportTicket}', [AdminSupportTicketController::class, 'update']);

        Route::get('/notifications', [AdminNotificationController::class, 'index']);
        Route::post('/notifications', [AdminNotificationController::class, 'store']);
        Route::delete('/notifications/{notification}', [AdminNotificationController::class, 'destroy']);
        Route::get('/notifications/banners', [AdminNotificationController::class, 'banners']);
        Route::post('/notifications/banners', [AdminNotificationController::class, 'storeBanner']);
        Route::delete('/notifications/banners/{banner}', [AdminNotificationController::class, 'destroyBanner']);

        Route::prefix('webhooks')->group(function () {
            Route::get('/tatum/raw', [AdminWebhookController::class, 'tatumRaw']);
            Route::get('/palmpay/raw', [AdminWebhookController::class, 'palmpayRaw']);
            Route::post('/tatum/raw/{id}/replay', [AdminWebhookController::class, 'replayTatum']);
            Route::post('/palmpay/raw/{id}/replay', [AdminWebhookController::class, 'replayPalmpay']);
        });

        Route::middleware('throttle:30,1')->group(function () {
            Route::post('/adjustments/fiat', [AdminAdjustmentController::class, 'fiat']);
            Route::post('/adjustments/crypto', [AdminAdjustmentController::class, 'crypto']);
        });

        Route::prefix('crypto')->group(function () {
            Route::get('/summary', [AdminCryptoTreasuryController::class, 'summary']);
            Route::get('/deposits', [AdminCryptoTreasuryController::class, 'deposits']);
            Route::get('/received-assets', [AdminCryptoTreasuryController::class, 'receivedAssets']);
            Route::get('/sweeps', [AdminCryptoTreasuryController::class, 'sweeps']);
            Route::post('/sweeps', [AdminCryptoTreasuryController::class, 'storeSweep']);
            Route::post('/sweeps/{id}/execute', [AdminCryptoTreasuryController::class, 'executeSweep']);
            Route::post('/sweeps/{id}/tx', [AdminCryptoTreasuryController::class, 'attachSweepTx']);
            Route::get('/external-sends', [AdminCryptoTreasuryController::class, 'externalSends']);

            Route::get('/vendors', [AdminCryptoVendorController::class, 'index']);
            Route::post('/vendors', [AdminCryptoVendorController::class, 'store']);
            Route::put('/vendors/{id}', [AdminCryptoVendorController::class, 'update']);

            Route::get('/wallet-currencies', [AdminWalletCurrencyController::class, 'index']);
            Route::put('/wallet-currencies/{id}/rate', [AdminWalletCurrencyController::class, 'updateRate']);

            Route::get('/users/{user}/virtual-accounts', [AdminCryptoExtensionController::class, 'userVirtualAccounts']);
            Route::get('/users/{user}/deposit-addresses', [AdminCryptoExtensionController::class, 'userDepositAddresses']);
            Route::get('/deposit-addresses', [AdminCryptoExtensionController::class, 'depositAddresses']);
            Route::get('/master-wallets', [AdminCryptoExtensionController::class, 'masterWallets']);
        });
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
        Route::get('/send/fee-preview', [CryptoController::class, 'previewSendFee']);
        Route::post('/send', [CryptoController::class, 'sendCrypto']);
    });

    // ========================================================================
    // VIRTUAL CARD ROUTES
    // ========================================================================
    Route::prefix('virtual-cards')->group(function () {
        Route::get('/pending-provider-events', [VirtualCardController::class, 'pendingProviderEvents']);
        Route::post('/provider-events/{providerEvent}/dismiss', [VirtualCardController::class, 'dismissProviderEvent']);
        Route::get('/', [VirtualCardController::class, 'index']);
        Route::get('/funding-estimate', [VirtualCardController::class, 'fundingEstimate']);
        Route::get('/creation-fee', [VirtualCardController::class, 'creationFee']);
        Route::post('/', [VirtualCardController::class, 'create']);
        Route::get('/{id}', [VirtualCardController::class, 'show']);
        Route::post('/{id}/fund', [VirtualCardController::class, 'fund']);
        Route::post('/{id}/withdraw', [VirtualCardController::class, 'withdraw']);
        Route::get('/{id}/transactions', [VirtualCardController::class, 'transactions']);
        Route::post('/{id}/terminate', [VirtualCardController::class, 'terminate']);
        Route::get('/{id}/check-3ds', [VirtualCardController::class, 'check3ds']);
        Route::get('/{id}/check-wallet', [VirtualCardController::class, 'checkWallet']);
        Route::post('/{id}/approve-3ds', [VirtualCardController::class, 'approve3ds']);
        Route::get('/{id}/spend-controls', [VirtualCardController::class, 'listSpendControls']);
        Route::post('/{id}/spend-controls', [VirtualCardController::class, 'createSpendControl']);
        Route::post('/{id}/delete-spend-control', [VirtualCardController::class, 'deleteSpendControl']);
        Route::get('/{id}/billing-address', [VirtualCardController::class, 'getBillingAddress']);
        Route::put('/{id}/billing-address', [VirtualCardController::class, 'updateBillingAddress']);
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
    Route::prefix('user')->group(function () {
        Route::get('/', function (Request $request) {
            return $request->user();
        });

        // Profile routes
        Route::get('/profile', [UserController::class, 'getProfile']);
        Route::put('/profile', [UserController::class, 'updateProfile']);

        // Notification routes
        Route::get('/notifications', [UserController::class, 'getNotifications']);
        Route::post('/notifications/{id}/read', [UserController::class, 'markNotificationAsRead']);
        Route::post('/notifications/read-all', [UserController::class, 'markAllNotificationsAsRead']);

        Route::post('/push-token', [PushTokenController::class, 'store']);
        Route::delete('/push-token', [PushTokenController::class, 'destroy']);
    });
});
