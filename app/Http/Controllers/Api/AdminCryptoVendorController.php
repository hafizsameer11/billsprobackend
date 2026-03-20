<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Models\CryptoVendor;
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
            'blockchain' => 'required|string|max:64',
            'currency' => 'required|string|max:32',
            'payout_address' => 'required|string|max:255',
            'contract_address' => 'nullable|string|max:255',
            'is_active' => 'sometimes|boolean',
            'metadata' => 'nullable|array',
        ]);

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
            'blockchain' => 'sometimes|string|max:64',
            'currency' => 'sometimes|string|max:32',
            'payout_address' => 'sometimes|string|max:255',
            'contract_address' => 'nullable|string|max:255',
            'is_active' => 'sometimes|boolean',
            'metadata' => 'nullable|array',
        ]);

        try {
            $updated = $this->treasury->updateVendor($vendor, $data);

            return ResponseHelper::success($updated, 'Vendor updated.');
        } catch (\Throwable $e) {
            Log::error('Admin vendor update failed', ['e' => $e->getMessage()]);

            return ResponseHelper::serverError('Could not update vendor.');
        }
    }
}
