<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Kyc\SubmitKycRequest;
use App\Services\KycService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

class KycController extends Controller
{
    protected KycService $kycService;

    public function __construct(KycService $kycService)
    {
        $this->kycService = $kycService;
    }

    /**
     * Submit or update KYC information
     */
    #[OA\Post(path: "/api/kyc", summary: "Submit or update KYC information", description: "Submit Know Your Customer (KYC) information including personal details, BVN, and NIN numbers.", security: [["sanctum" => []]], tags: ["KYC"])]
    #[OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: "first_name", type: "string", nullable: true, example: "John"), new OA\Property(property: "last_name", type: "string", nullable: true, example: "Doe"), new OA\Property(property: "email", type: "string", format: "email", nullable: true, example: "john.doe@example.com"), new OA\Property(property: "date_of_birth", type: "string", format: "date", nullable: true, example: "1990-01-01"), new OA\Property(property: "bvn_number", type: "string", nullable: true, example: "12345678901"), new OA\Property(property: "nin_number", type: "string", nullable: true, example: "12345678901")]))]
    #[OA\Response(response: 200, description: "KYC information submitted successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "message", type: "string", example: "KYC information submitted successfully"), new OA\Property(property: "data", type: "object")]))]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    #[OA\Response(response: 422, description: "Validation error")]
    public function submit(SubmitKycRequest $request): JsonResponse
    {
        try {
            $result = $this->kycService->submitKyc($request->user()->id, $request->validated());

            return ResponseHelper::success($result, $result['message'] ?? 'KYC information submitted successfully.');
        } catch (\Exception $e) {
            Log::error('KYC submission error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while submitting KYC information. Please try again.');
        }
    }

    /**
     * Get KYC information
     */
    #[OA\Get(path: "/api/kyc", summary: "Get KYC information", description: "Retrieve the current KYC information and status for the authenticated user.", security: [["sanctum" => []]], tags: ["KYC"])]
    #[OA\Response(response: 200, description: "KYC information retrieved successfully", content: new OA\JsonContent(properties: [new OA\Property(property: "success", type: "boolean", example: true), new OA\Property(property: "message", type: "string", example: "KYC information retrieved successfully"), new OA\Property(property: "data", type: "object", nullable: true)]))]
    #[OA\Response(response: 401, description: "Unauthenticated")]
    public function get(Request $request): JsonResponse
    {
        try {
            $kyc = $this->kycService->getKyc($request->user()->id);

            return ResponseHelper::success(['kyc' => $kyc], 'KYC information retrieved successfully.');
        } catch (\Exception $e) {
            Log::error('Get KYC error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'trace' => $e->getTraceAsString(),
            ]);

            return ResponseHelper::serverError('An error occurred while retrieving KYC information. Please try again.');
        }
    }
}
