<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Models\CryptoVendor;
use App\Models\WalletCurrency;
use App\Services\Crypto\CryptoTreasuryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AdminCryptoVendorController extends Controller
{
    public function __construct(
        protected CryptoTreasuryService $treasury
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $activeOnly = filter_var($request->query('active_only', true), FILTER_VALIDATE_BOOL);
            $rows = $this->treasury->listVendors($activeOnly);

            return ResponseHelper::success($rows, 'Vendors retrieved.');
        } catch (\Throwable $e) {
            Log::error('Admin vendors list failed', ['e' => $e->getMessage()]);

            return ResponseHelper::serverError('Could not load vendors.');
        }
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:64|unique:crypto_vendors,code',
            'wallet_currency_id' => 'nullable|integer|exists:wallet_currencies,id',
            'blockchain' => 'required_without:wallet_currency_id|string|max:64',
            'currency' => 'required_without:wallet_currency_id|string|max:32',
            'payout_address' => 'required|string|max:255',
            'contract_address' => 'nullable|string|max:255',
            'is_active' => 'sometimes|boolean',
            'metadata' => 'nullable|array',
        ]);

        $this->applyWalletCurrencyToVendorPayload($data);

        try {
            $vendor = $this->treasury->createVendor($data);

            return ResponseHelper::success($vendor, 'Vendor created.', 201);
        } catch (\Throwable $e) {
            Log::error('Admin vendor create failed', ['e' => $e->getMessage()]);

            return ResponseHelper::serverError('Could not create vendor.');
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $vendor = CryptoVendor::query()->findOrFail($id);
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'code' => 'sometimes|string|max:64|unique:crypto_vendors,code,'.$vendor->id,
            'wallet_currency_id' => 'nullable|integer|exists:wallet_currencies,id',
            'blockchain' => 'sometimes|string|max:64',
            'currency' => 'sometimes|string|max:32',
            'payout_address' => 'sometimes|string|max:255',
            'contract_address' => 'nullable|string|max:255',
            'is_active' => 'sometimes|boolean',
            'metadata' => 'nullable|array',
        ]);

        if (array_key_exists('wallet_currency_id', $data) && $data['wallet_currency_id']) {
            $this->applyWalletCurrencyToVendorPayload($data);
        }

        try {
            $updated = $this->treasury->updateVendor($vendor, $data);

            return ResponseHelper::success($updated, 'Vendor updated.');
        } catch (\Throwable $e) {
            Log::error('Admin vendor update failed', ['e' => $e->getMessage()]);

            return ResponseHelper::serverError('Could not update vendor.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function applyWalletCurrencyToVendorPayload(array &$data): void
    {
        $wcId = $data['wallet_currency_id'] ?? null;
        if (! $wcId) {
            return;
        }

        $wc = WalletCurrency::query()->findOrFail((int) $wcId);
        $data['blockchain'] = $wc->blockchain;
        $data['currency'] = strtoupper((string) $wc->currency);
        if (empty($data['contract_address']) && $wc->contract_address) {
            $data['contract_address'] = $wc->contract_address;
        }
    }
}
