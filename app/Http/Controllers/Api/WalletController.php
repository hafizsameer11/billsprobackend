<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Services\Wallet\WalletService;
use App\Services\Crypto\CryptoWalletService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

class WalletController extends Controller
{
    protected WalletService $walletService;
    protected CryptoWalletService $cryptoWalletService;

    public function __construct(
        WalletService $walletService,
        CryptoWalletService $cryptoWalletService
    ) {
        $this->walletService = $walletService;
        $this->cryptoWalletService = $cryptoWalletService;
    }

    /**
     * Get wallet balance (fiat + crypto in USD)
     */
    #[OA\Get(path: "/api/wallet/balance", summary: "Get wallet balance", description: "Get total wallet balance including fiat (NGN) and crypto balances. Crypto balances are converted to USD using exchange rates from wallet currencies.", security: [["sanctum" => []]], tags: ["Wallet"])]
    #[OA\Response(response: 200, description: "Balance retrieved successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "message", type: "string", example: "Balance retrieved successfully"), new OA\Property(property: "data", type: "object")]))]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    public function getBalance(Request $request): JsonResponse
    {
        try {
            $userId = $request->user()->id;

            // Get fiat wallet balance (NGN)
            $fiatBalance = $this->walletService->getTotalFiatBalance($userId, 'NGN');

            // Get total crypto balance in USD
            $cryptoBalanceUsd = $this->cryptoWalletService->getTotalCryptoBalanceInUsd($userId);

            // Get crypto breakdown
            $cryptoBreakdown = $this->cryptoWalletService->getCryptoBalanceBreakdown($userId);

            $data = [
                'fiat' => [
                    'currency' => 'NGN',
                    'balance' => $fiatBalance,
                ],
                'crypto' => [
                    'total_usd' => $cryptoBalanceUsd,
                    'breakdown' => $cryptoBreakdown,
                ],
                'total' => [
                    'fiat_ngn' => $fiatBalance,
                    'crypto_usd' => $cryptoBalanceUsd,
                ],
            ];

            return ResponseHelper::success($data, 'Balance retrieved successfully.');
        } catch (\Exception $e) {
            Log::error('Get balance error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while retrieving balance. Please try again.');
        }
    }

    /**
     * Get fiat wallets
     */
    #[OA\Get(path: "/api/wallet/fiat", summary: "Get fiat wallets", description: "Get all fiat wallets for the authenticated user.", security: [["sanctum" => []]], tags: ["Wallet"])]
    #[OA\Response(response: 200, description: "Fiat wallets retrieved successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "message", type: "string", example: "Fiat wallets retrieved successfully"), new OA\Property(property: "data", type: "array", items: new OA\Items(type: "object"))]))]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    public function getFiatWallets(Request $request): JsonResponse
    {
        try {
            $wallets = $this->walletService->getUserFiatWallets($request->user()->id);

            return ResponseHelper::success($wallets, 'Fiat wallets retrieved successfully.');
        } catch (\Exception $e) {
            Log::error('Get fiat wallets error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while retrieving fiat wallets. Please try again.');
        }
    }

    /**
     * Get crypto wallets (virtual accounts)
     */
    #[OA\Get(path: "/api/wallet/crypto", summary: "Get crypto wallets (virtual accounts)", description: "Get all crypto virtual accounts for the authenticated user. These are automatically created when the user verifies their email.", security: [["sanctum" => []]], tags: ["Wallet"])]
    #[OA\Response(response: 200, description: "Crypto wallets retrieved successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "message", type: "string", example: "Crypto wallets retrieved successfully"), new OA\Property(property: "data", type: "array", items: new OA\Items(type: "object"))]))]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    public function getCryptoWallets(Request $request): JsonResponse
    {
        try {
            $wallets = $this->cryptoWalletService->getUserVirtualAccounts($request->user()->id);

            return ResponseHelper::success($wallets, 'Crypto wallets retrieved successfully.');
        } catch (\Exception $e) {
            Log::error('Get crypto wallets error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while retrieving crypto wallets. Please try again.');
        }
    }
}
