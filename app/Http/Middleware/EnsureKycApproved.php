<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks money-moving / spend actions until KYC is approved.
 * Deposits, crypto receive, auth, profile, and KYC itself stay ungated.
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
            return response()->json([
                'success' => false,
                'message' => 'Your KYC is pending approval. Please wait until it is approved before continuing.',
                'code' => 'KYC_PENDING',
                'kyc_status' => 'pending',
            ], 403);
        }

        if ($status === 'rejected') {
            return response()->json([
                'success' => false,
                'message' => 'Your KYC was rejected. Please update your KYC and submit again before continuing.',
                'code' => 'KYC_REJECTED',
                'kyc_status' => 'rejected',
            ], 403);
        }

        return response()->json([
            'success' => false,
            'message' => 'Please complete KYC verification first before continuing.',
            'code' => 'KYC_REQUIRED',
            'kyc_status' => 'missing',
        ], 403);
    }
}
