<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Services\Crypto\CryptoService;
use App\Services\Crypto\CryptoWalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Crypto',
    description: 'Crypto virtual accounts: balances, on-chain deposit addresses via Tatum, buy/sell, and send. Production uses real Tatum (`TATUM_USE_MOCK=false`). Deposit responses keep the same JSON shape (`success`, `message`, `data` with `deposit_address`, `qr_code`, `account_id`, etc.).'
)]
class CryptoController extends Controller
{
    protected CryptoService $cryptoService;

    protected CryptoWalletService $cryptoWalletService;

    public function __construct(
        CryptoService $cryptoService,
        CryptoWalletService $cryptoWalletService
    ) {
        $this->cryptoService = $cryptoService;
        $this->cryptoWalletService = $cryptoWalletService;
    }

    /**
     * Get USDT blockchains
     */
    #[OA\Get(path: '/api/crypto/usdt/blockchains', summary: 'Get USDT blockchains', description: 'Get all available blockchains for USDT.', security: [['sanctum' => []]], tags: ['Crypto'])]
    #[OA\Response(response: 200, description: 'Blockchains retrieved successfully', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean', example: true), new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))]))]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    public function getUsdtBlockchains(): JsonResponse
    {
        try {
            $blockchains = $this->cryptoService->getUsdtBlockchains();

            return ResponseHelper::success($blockchains, 'USDT blockchains retrieved successfully.');
        } catch (\Exception $e) {
            Log::error('Get USDT blockchains error: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);

            return ResponseHelper::serverError('An error occurred while retrieving blockchains. Please try again.');
        }
    }

    /**
     * Get USDC networks (e.g. Ethereum ERC-20 + BSC BEP-20).
     */
    #[OA\Get(path: '/api/crypto/usdc/blockchains', summary: 'Get USDC networks', description: 'Networks where USDC is enabled (matches `wallet_currencies`).', security: [['sanctum' => []]], tags: ['Crypto'])]
    #[OA\Response(response: 200, description: 'Networks retrieved successfully', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean', example: true), new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))]))]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    public function getUsdcBlockchains(): JsonResponse
    {
        try {
            $blockchains = $this->cryptoService->getUsdcBlockchains();

            return ResponseHelper::success($blockchains, 'USDC networks retrieved successfully.');
        } catch (\Exception $e) {
            Log::error('Get USDC networks error: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);

            return ResponseHelper::serverError('An error occurred while retrieving networks. Please try again.');
        }
    }

    /**
     * Get virtual accounts (grouped - USDT as one)
     */
    #[OA\Get(path: '/api/crypto/accounts', summary: 'Get virtual accounts', description: 'Get all virtual accounts for the user. USDT accounts are grouped together.', security: [['sanctum' => []]], tags: ['Crypto'])]
    #[OA\Response(response: 200, description: 'Accounts retrieved successfully', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean', example: true), new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))]))]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    public function getAccounts(Request $request): JsonResponse
    {
        try {
            $accounts = $this->cryptoService->getVirtualAccountsGrouped($request->user()->id);

            return ResponseHelper::success($accounts, 'Virtual accounts retrieved successfully.');
        } catch (\Exception $e) {
            Log::error('Get accounts error: '.$e->getMessage(), [
                'user_id' => $request->user()->id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while retrieving accounts. Please try again.');
        }
    }

    /**
     * Get account details
     */
    #[OA\Get(path: '/api/crypto/accounts/{currency}', summary: 'Get account details', description: 'Get detailed information about a specific crypto account.', security: [['sanctum' => []]], tags: ['Crypto'])]
    #[OA\Parameter(name: 'currency', in: 'path', required: true, description: 'Currency code', schema: new OA\Schema(type: 'string', example: 'BTC'))]
    #[OA\Parameter(name: 'blockchain', in: 'query', required: false, description: 'Blockchain (required for USDT)', schema: new OA\Schema(type: 'string', example: 'ETH'))]
    #[OA\Response(response: 200, description: 'Account details retrieved successfully', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean', example: true), new OA\Property(property: 'data', type: 'object')]))]
    #[OA\Response(response: 404, description: 'Account not found')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    public function getAccountDetails(Request $request, string $currency): JsonResponse
    {
        try {
            $blockchain = $request->query('blockchain');
            $account = $this->cryptoService->getAccountDetails($request->user()->id, $currency, $blockchain);

            if (! $account) {
                return ResponseHelper::notFound('Account not found');
            }

            return ResponseHelper::success($account, 'Account details retrieved successfully.');
        } catch (\Exception $e) {
            Log::error('Get account details error: '.$e->getMessage(), [
                'user_id' => $request->user()->id,
                'currency' => $currency,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while retrieving account details. Please try again.');
        }
    }

    /**
     * Get deposit address (Tatum-backed on-chain address; same response shape as before)
     */
    #[OA\Get(
        path: '/api/crypto/deposit-address',
        summary: 'Get deposit address',
        description: 'Returns the persisted on-chain deposit address for this currency/blockchain (Tatum user wallet + V4 webhooks when `TATUM_USE_MOCK=false`). Response envelope: `success`, `message`, `data`.',
        security: [['sanctum' => []]],
        tags: ['Crypto']
    )]
    #[OA\Parameter(name: 'currency', in: 'query', required: true, description: 'Currency code (must match wallet_currencies / virtual_accounts)', schema: new OA\Schema(type: 'string', example: 'ETH'))]
    #[OA\Parameter(name: 'blockchain', in: 'query', required: true, description: 'Blockchain key (must match virtual_accounts.blockchain)', schema: new OA\Schema(type: 'string', example: 'ethereum'))]
    #[OA\Response(
        response: 200,
        description: 'Standard success envelope with `data` unchanged for mobile/web clients.',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Deposit address retrieved successfully.'),
                new OA\Property(
                    property: 'data',
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'currency', type: 'string', example: 'ETH'),
                        new OA\Property(property: 'blockchain', type: 'string', example: 'ethereum'),
                        new OA\Property(property: 'network', type: 'string', example: 'ethereum', description: 'Alias of blockchain for clients that expect a network label'),
                        new OA\Property(property: 'deposit_address', type: 'string', description: 'On-chain address from Tatum (or dev-only mock)'),
                        new OA\Property(property: 'qr_code', type: 'string', description: 'Same value as deposit_address for QR encoding'),
                        new OA\Property(property: 'account_id', type: 'integer', description: 'Internal virtual_accounts.id'),
                    ]
                ),
            ]
        )
    )]
    #[OA\Response(response: 400, description: 'Missing query params, account not found, or Tatum/configuration error')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    public function getDepositAddress(Request $request): JsonResponse
    {
        try {
            $currency = $request->query('currency');
            $blockchain = $request->query('blockchain');

            if (! $currency || ! $blockchain) {
                return ResponseHelper::error('Currency and blockchain are required', 400);
            }

            $result = $this->cryptoService->getDepositAddress($request->user()->id, $currency, $blockchain);

            if (! $result['success']) {
                return ResponseHelper::error($result['message'] ?? 'Failed to get deposit address', 400);
            }

            return ResponseHelper::success($result['data'], 'Deposit address retrieved successfully.');
        } catch (\Exception $e) {
            Log::error('Get deposit address error: '.$e->getMessage(), [
                'user_id' => $request->user()->id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while retrieving deposit address. Please try again.');
        }
    }

    /**
     * Get exchange rate
     */
    #[OA\Get(path: '/api/crypto/exchange-rate', summary: 'Get exchange rate', description: 'Get exchange rate for buying or selling crypto. Pass `blockchain` when the crypto symbol exists on multiple chains (e.g. USDT on tron vs ethereum).', security: [['sanctum' => []]], tags: ['Crypto'])]
    #[OA\Parameter(name: 'from_currency', in: 'query', required: true, description: 'From currency', schema: new OA\Schema(type: 'string', example: 'NGN'))]
    #[OA\Parameter(name: 'to_currency', in: 'query', required: true, description: 'To currency', schema: new OA\Schema(type: 'string', example: 'BTC'))]
    #[OA\Parameter(name: 'amount', in: 'query', required: true, description: 'Amount', schema: new OA\Schema(type: 'number', example: 1000))]
    #[OA\Parameter(name: 'blockchain', in: 'query', required: false, description: 'Normalized chain (e.g. tron, ethereum) — required for multi-chain tokens like USDT', schema: new OA\Schema(type: 'string', example: 'tron'))]
    #[OA\Response(response: 200, description: 'Exchange rate retrieved successfully', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean', example: true), new OA\Property(property: 'data', type: 'object')]))]
    #[OA\Response(response: 400, description: 'Invalid request')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    public function getExchangeRate(Request $request): JsonResponse
    {
        try {
            $fromCurrency = $request->query('from_currency');
            $toCurrency = $request->query('to_currency');
            $amount = $request->query('amount');
            $blockchain = $request->query('blockchain');

            if (! $fromCurrency || ! $toCurrency || ! $amount) {
                return ResponseHelper::error('From currency, to currency, and amount are required', 400);
            }

            $result = $this->cryptoService->getExchangeRate($fromCurrency, $toCurrency, (float) $amount, $blockchain ? (string) $blockchain : null);

            if (! $result['success']) {
                return ResponseHelper::error($result['message'] ?? 'Failed to get exchange rate', 400);
            }

            return ResponseHelper::success($result['data'], 'Exchange rate retrieved successfully.')
                ->header('Cache-Control', 'no-store, private, must-revalidate');
        } catch (\Exception $e) {
            Log::error('Get exchange rate error: '.$e->getMessage(), [
                'user_id' => $request->user()->id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while retrieving exchange rate. Please try again.');
        }
    }

    /**
     * Preview buy crypto
     */
    #[OA\Post(path: '/api/crypto/buy/preview', summary: 'Preview buy crypto', description: 'Preview buy crypto transaction with fees and exchange rate. The amount parameter is in NGN (Naira) - the amount you want to spend to buy crypto.', security: [['sanctum' => []]], tags: ['Crypto'])]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(required: ['currency', 'blockchain', 'amount'], properties: [new OA\Property(property: 'currency', type: 'string', example: 'BTC', description: 'Crypto currency to buy'), new OA\Property(property: 'blockchain', type: 'string', example: 'BTC', description: 'Blockchain network'), new OA\Property(property: 'amount', type: 'number', example: 1000, description: 'Amount in NGN (Naira) to spend on buying crypto'), new OA\Property(property: 'payment_method', type: 'string', enum: ['naira'], example: 'naira', description: 'Debit NGN fiat wallet (fund via PalmPay deposit first)')]))]
    #[OA\Response(response: 200, description: 'Preview retrieved successfully', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean', example: true), new OA\Property(property: 'data', type: 'object')]))]
    #[OA\Response(response: 400, description: 'Invalid request')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    public function previewBuyCrypto(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'currency' => 'required|string',
                'blockchain' => 'required|string',
                'amount' => 'required|numeric|min:0.01',
                'payment_method' => 'nullable|string|in:naira',
            ]);

            $result = $this->cryptoService->previewBuyCrypto($request->user()->id, $data);

            if (! $result['success']) {
                return ResponseHelper::error($result['message'] ?? 'Failed to preview transaction', 400);
            }

            return ResponseHelper::success($result['data'], 'Preview retrieved successfully.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ResponseHelper::validationError($e->errors());
        } catch (\Exception $e) {
            Log::error('Preview buy crypto error: '.$e->getMessage(), [
                'user_id' => $request->user()->id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while previewing transaction. Please try again.');
        }
    }

    /**
     * Confirm buy crypto
     */
    #[OA\Post(path: '/api/crypto/buy/confirm', summary: 'Confirm buy crypto', description: 'Confirm and execute buy crypto transaction. The amount parameter is in NGN (Naira) - the amount you want to spend to buy crypto.', security: [['sanctum' => []]], tags: ['Crypto'])]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(required: ['currency', 'blockchain', 'amount'], properties: [new OA\Property(property: 'currency', type: 'string', example: 'BTC', description: 'Crypto currency to buy'), new OA\Property(property: 'blockchain', type: 'string', example: 'BTC', description: 'Blockchain network'), new OA\Property(property: 'amount', type: 'number', example: 1000, description: 'Amount in NGN (Naira) to spend on buying crypto'), new OA\Property(property: 'payment_method', type: 'string', enum: ['naira'], example: 'naira', description: 'Debit NGN fiat wallet')]))]
    #[OA\Response(response: 200, description: 'Transaction completed successfully', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean', example: true), new OA\Property(property: 'data', type: 'object')]))]
    #[OA\Response(response: 400, description: 'Invalid request or insufficient balance')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    public function confirmBuyCrypto(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'currency' => 'required|string',
                'blockchain' => 'required|string',
                'amount' => 'required|numeric|min:0.01',
                'payment_method' => 'nullable|string|in:naira',
            ]);

            $result = $this->cryptoService->confirmBuyCrypto($request->user()->id, $data);

            if (! $result['success']) {
                return ResponseHelper::error($result['message'] ?? 'Transaction failed', 400);
            }

            return ResponseHelper::success($result['data'], $result['message'] ?? 'Transaction completed successfully.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ResponseHelper::validationError($e->errors());
        } catch (\Exception $e) {
            Log::error('Confirm buy crypto error: '.$e->getMessage(), [
                'user_id' => $request->user()->id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while processing transaction. Please try again.');
        }
    }

    /**
     * Preview sell crypto
     */
    #[OA\Post(path: '/api/crypto/sell/preview', summary: 'Preview sell crypto', description: 'Preview sell crypto transaction with fees and exchange rate. The amount parameter is in the crypto currency - the amount of crypto you want to sell.', security: [['sanctum' => []]], tags: ['Crypto'])]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(required: ['currency', 'blockchain', 'amount'], properties: [new OA\Property(property: 'currency', type: 'string', example: 'BTC', description: 'Crypto currency to sell'), new OA\Property(property: 'blockchain', type: 'string', example: 'BTC', description: 'Blockchain network'), new OA\Property(property: 'amount', type: 'number', example: 0.001, description: 'Amount in crypto currency to sell (e.g., 0.001 BTC)')]))]
    #[OA\Response(response: 200, description: 'Preview retrieved successfully', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean', example: true), new OA\Property(property: 'data', type: 'object')]))]
    #[OA\Response(response: 400, description: 'Invalid request or insufficient balance')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    public function previewSellCrypto(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'currency' => 'required|string',
                'blockchain' => 'required|string',
                'amount' => 'required|numeric|min:0.00000001',
            ]);

            $result = $this->cryptoService->previewSellCrypto($request->user()->id, $data);

            if (! $result['success']) {
                return ResponseHelper::error($result['message'] ?? 'Failed to preview transaction', 400);
            }

            return ResponseHelper::success($result['data'], 'Preview retrieved successfully.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ResponseHelper::validationError($e->errors());
        } catch (\Exception $e) {
            Log::error('Preview sell crypto error: '.$e->getMessage(), [
                'user_id' => $request->user()->id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while previewing transaction. Please try again.');
        }
    }

    /**
     * Confirm sell crypto
     */
    #[OA\Post(path: '/api/crypto/sell/confirm', summary: 'Confirm sell crypto', description: 'Confirm and execute sell crypto transaction. The amount parameter is in the crypto currency - the amount of crypto you want to sell.', security: [['sanctum' => []]], tags: ['Crypto'])]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(required: ['currency', 'blockchain', 'amount'], properties: [new OA\Property(property: 'currency', type: 'string', example: 'BTC', description: 'Crypto currency to sell'), new OA\Property(property: 'blockchain', type: 'string', example: 'BTC', description: 'Blockchain network'), new OA\Property(property: 'amount', type: 'number', example: 0.001, description: 'Amount in crypto currency to sell (e.g., 0.001 BTC)')]))]
    #[OA\Response(response: 200, description: 'Transaction completed successfully', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean', example: true), new OA\Property(property: 'data', type: 'object')]))]
    #[OA\Response(response: 400, description: 'Invalid request or insufficient balance')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    public function confirmSellCrypto(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'currency' => 'required|string',
                'blockchain' => 'required|string',
                'amount' => 'required|numeric|min:0.00000001',
            ]);

            $result = $this->cryptoService->confirmSellCrypto($request->user()->id, $data);

            if (! $result['success']) {
                return ResponseHelper::error($result['message'] ?? 'Transaction failed', 400);
            }

            return ResponseHelper::success($result['data'], $result['message'] ?? 'Transaction completed successfully.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ResponseHelper::validationError($e->errors());
        } catch (\Exception $e) {
            Log::error('Confirm sell crypto error: '.$e->getMessage(), [
                'user_id' => $request->user()->id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while processing transaction. Please try again.');
        }
    }

    /**
     * Preview send / withdrawal processing fee (USD-based admin rate or default).
     */
    #[OA\Get(path: '/api/crypto/send/fee-preview', summary: 'Preview send processing fee', security: [['sanctum' => []]], tags: ['Crypto'])]
    public function previewSendFee(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'currency' => 'required|string',
                'blockchain' => 'required|string',
                'amount' => 'nullable|numeric|min:0',
            ]);
            $amount = (float) ($data['amount'] ?? 0);
            $result = $this->cryptoService->previewSendProcessingFee(
                $data['currency'],
                $data['blockchain'],
                $amount
            );

            if (! $result['success']) {
                return ResponseHelper::error($result['message'] ?? 'Failed to quote fee', 400);
            }

            return ResponseHelper::success($result['data'], 'Fee preview.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ResponseHelper::validationError($e->errors());
        } catch (\Exception $e) {
            Log::error('Send fee preview error: '.$e->getMessage());

            return ResponseHelper::serverError('Unable to load fee preview.');
        }
    }

    /**
     * Send crypto (withdrawal)
     */
    #[OA\Post(path: '/api/crypto/send', summary: 'Send crypto', description: 'Debits the user virtual account; on-chain transfer is intended from the platform master/hot wallet (pending tx until broadcast).', security: [['sanctum' => []]], tags: ['Crypto'])]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(required: ['currency', 'blockchain', 'amount', 'address'], properties: [new OA\Property(property: 'currency', type: 'string', example: 'BTC'), new OA\Property(property: 'blockchain', type: 'string', example: 'BTC'), new OA\Property(property: 'amount', type: 'number', example: 0.001), new OA\Property(property: 'address', type: 'string', example: '1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa'), new OA\Property(property: 'network', type: 'string', example: 'BTC')]))]
    #[OA\Response(response: 200, description: 'Crypto sent successfully', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean', example: true), new OA\Property(property: 'data', type: 'object')]))]
    #[OA\Response(response: 400, description: 'Invalid request or insufficient balance')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    public function sendCrypto(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'currency' => 'required|string',
                'blockchain' => 'required|string',
                'amount' => 'required|numeric|min:0.00000001',
                'address' => 'required|string',
                'network' => 'nullable|string',
            ]);

            $result = $this->cryptoService->sendCrypto($request->user()->id, $data);

            if (! $result['success']) {
                return ResponseHelper::error($result['message'] ?? 'Transaction failed', 400);
            }

            return ResponseHelper::success($result['data'], $result['message'] ?? 'Crypto sent successfully.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ResponseHelper::validationError($e->errors());
        } catch (\Exception $e) {
            Log::error('Send crypto error: '.$e->getMessage(), [
                'user_id' => $request->user()->id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while sending crypto. Please try again.');
        }
    }
}
