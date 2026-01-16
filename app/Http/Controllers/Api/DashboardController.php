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

class DashboardController extends Controller
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
     * Get dashboard data
     */
    #[OA\Get(path: "/api/dashboard", summary: "Get dashboard data", description: "Get user dashboard data including balances, recent transactions, and quick stats.", security: [["sanctum" => []]], tags: ["Dashboard"])]
    #[OA\Response(response: 200, description: "Dashboard data retrieved successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "message", type: "string", example: "Dashboard data retrieved successfully"), new OA\Property(property: "data", type: "object")]))]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    public function index(Request $request): JsonResponse
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
                'user' => [
                    'name' => $request->user()->name,
                    'first_name' => $request->user()->first_name,
                    'last_name' => $request->user()->last_name,
                    'email' => $request->user()->email,
                ],
                'balances' => [
                    'fiat' => [
                        'currency' => 'NGN',
                        'balance' => $fiatBalance,
                    ],
                    'crypto' => [
                        'total_usd' => $cryptoBalanceUsd,
                        'breakdown' => $cryptoBreakdown,
                    ],
                ],
            ];

            return ResponseHelper::success($data, 'Dashboard data retrieved successfully.');
        } catch (\Exception $e) {
            Log::error('Get dashboard error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while retrieving dashboard data. Please try again.');
        }
    }
}
