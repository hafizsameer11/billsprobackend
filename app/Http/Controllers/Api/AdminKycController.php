<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Models\Kyc;
use App\Models\User;
use App\Services\Admin\AdminKycService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminKycController extends Controller
{
    public function __construct(
        protected AdminKycService $adminKyc
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));

        if ($request->query('scope') === 'unverified') {
            $search = trim((string) $request->query('search', ''));
            $q = User::query()->whereDoesntHave('kyc')->orderByDesc('id');
            if ($search !== '') {
                $like = '%'.$search.'%';
                $q->where(function ($w) use ($like) {
                    $w->where('name', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhere('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhere('phone_number', 'like', $like);
                });
            }

            return ResponseHelper::success($q->paginate($perPage), 'Users without KYC retrieved.');
        }

        $q = Kyc::query()->with('user')->orderByDesc('id');

        if ($request->filled('status')) {
            $q->where('status', $request->query('status'));
        }

        if ($request->filled('search')) {
            $term = trim((string) $request->query('search'));
            if ($term !== '') {
                $like = '%'.$term.'%';
                $q->whereHas('user', function ($uq) use ($like) {
                    $uq->where('name', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhere('phone_number', 'like', $like);
                });
            }
        }

        return ResponseHelper::success($q->paginate($perPage), 'KYC records retrieved.');
    }

    public function show(User $user): JsonResponse
    {
        $kyc = Kyc::query()->where('user_id', $user->id)->first();

        return ResponseHelper::success([
            'user' => $user,
            'kyc' => $kyc,
        ], 'KYC retrieved.');
    }

    public function approve(Request $request, User $user): JsonResponse
    {
        $kyc = $this->adminKyc->approve($user, (int) $request->user()->id, $request);

        return ResponseHelper::success($kyc, 'KYC approved.');
    }

    public function reject(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'reason' => 'required|string|max:2000',
        ]);

        $kyc = $this->adminKyc->reject($user, (int) $request->user()->id, $data['reason'], $request);

        return ResponseHelper::success($kyc, 'KYC rejected.');
    }
}
