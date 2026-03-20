<?php

namespace App\Services\Admin;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class AdminUserService
{
    public function __construct(
        protected AdminAuditService $audit
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginateUsers(int $perPage, array $filters = []): LengthAwarePaginator
    {
        $q = User::query()->orderByDesc('id');

        if (! empty($filters['search'])) {
            $s = '%'.$filters['search'].'%';
            $q->where(function ($w) use ($s) {
                $w->where('email', 'like', $s)
                    ->orWhere('name', 'like', $s)
                    ->orWhere('phone_number', 'like', $s);
            });
        }
        if (! empty($filters['account_status'])) {
            $q->where('account_status', $filters['account_status']);
        }
        if (isset($filters['is_admin'])) {
            $q->where('is_admin', filter_var($filters['is_admin'], FILTER_VALIDATE_BOOL));
        }

        return $q->paginate($perPage);
    }

    public function suspend(User $user, int $adminId, ?string $reason, ?\Illuminate\Http\Request $request = null): User
    {
        $user->update([
            'account_status' => 'suspended',
            'suspended_at' => now(),
            'suspension_reason' => $reason,
        ]);
        $this->audit->log($adminId, 'user.suspend', $user, ['reason' => $reason], $request);

        return $user->fresh();
    }

    public function ban(User $user, int $adminId, ?string $reason, ?\Illuminate\Http\Request $request = null): User
    {
        $user->update([
            'account_status' => 'banned',
            'suspended_at' => now(),
            'suspension_reason' => $reason,
        ]);
        $this->audit->log($adminId, 'user.ban', $user, ['reason' => $reason], $request);

        return $user->fresh();
    }

    public function activate(User $user, int $adminId, ?\Illuminate\Http\Request $request = null): User
    {
        $user->update([
            'account_status' => 'active',
            'suspended_at' => null,
            'suspension_reason' => null,
        ]);
        $this->audit->log($adminId, 'user.activate', $user, [], $request);

        return $user->fresh();
    }

    public function updateInternalNotes(User $user, int $adminId, ?string $notes, ?\Illuminate\Http\Request $request = null): User
    {
        $user->update(['internal_notes' => $notes]);
        $this->audit->log($adminId, 'user.notes', $user, [], $request);

        return $user->fresh();
    }

    public function revokeAllTokens(User $user, int $adminId, ?\Illuminate\Http\Request $request = null): void
    {
        DB::table('personal_access_tokens')->where('tokenable_type', User::class)->where('tokenable_id', $user->id)->delete();
        $this->audit->log($adminId, 'user.tokens.revoke', $user, [], $request);
    }
}
