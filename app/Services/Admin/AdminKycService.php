<?php

namespace App\Services\Admin;

use App\Models\Kyc;
use App\Models\User;
use App\Services\KycService;
use Illuminate\Http\Request;

class AdminKycService
{
    public function __construct(
        protected KycService $kycService,
        protected AdminAuditService $audit
    ) {}

    public function approve(User $user, int $adminUserId, ?Request $request = null): Kyc
    {
        $kyc = Kyc::query()->firstOrCreate(['user_id' => $user->id], [
            'first_name' => $user->first_name ?? $user->name,
            'last_name' => $user->last_name ?? '',
            'email' => $user->email,
            'status' => 'pending',
        ]);

        $this->kycService->updateKycStatus($kyc->id, 'approved', null);
        $this->audit->log($adminUserId, 'kyc.approve', $kyc->fresh(), [], $request);

        return $kyc->fresh();
    }

    public function reject(User $user, int $adminUserId, string $reason, ?Request $request = null): Kyc
    {
        $kyc = Kyc::query()->where('user_id', $user->id)->firstOrFail();

        $this->kycService->updateKycStatus($kyc->id, 'rejected', $reason);
        $this->audit->log($adminUserId, 'kyc.reject', $kyc->fresh(), ['reason' => $reason], $request);

        return $kyc->fresh();
    }
}
