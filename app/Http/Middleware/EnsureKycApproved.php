<?php

namespace App\Http\Middleware;

use App\Helpers\ResponseHelper;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks money-moving / spend actions until KYC is approved.
 * Deposits, crypto receive, auth, profile, and KYC itself stay ungated.
 *
 * Response shape matches ResponseHelper::error so the mobile app can show
 * error.message / error.response.data.message in alerts.
 */
class EnsureKycApproved
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        $user->loadMissing('kyc');
        $status = strtolower((string) ($user->kyc?->status ?? ''));

        if ($status === 'approved') {
            return $next($request);
        }

        if ($status === 'pending') {
            return ResponseHelper::error(
                'Your KYC is pending approval. Please wait until it is approved before continuing.',
                400,
                [
                    'code' => 'KYC_PENDING',
                    'kyc_status' => 'pending',
                ]
            );
        }

        if ($status === 'rejected') {
            return ResponseHelper::error(
                'Your KYC was rejected. Please update your KYC and submit again before continuing.',
                400,
                [
                    'code' => 'KYC_REJECTED',
                    'kyc_status' => 'rejected',
                ]
            );
        }

        return ResponseHelper::error(
            'Please complete KYC verification first before continuing.',
            400,
            [
                'code' => 'KYC_REQUIRED',
                'kyc_status' => 'missing',
            ]
        );
    }
}
