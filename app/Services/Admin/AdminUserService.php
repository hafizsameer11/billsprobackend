<?php

namespace App\Services\Admin;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

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

        if (! empty($filters['kyc_filter']) && in_array($filters['kyc_filter'], ['verified', 'pending'], true)) {
            if ($filters['kyc_filter'] === 'verified') {
                $q->where('kyc_completed', true);
            } else {
                $q->whereHas('kyc', function ($k) {
                    $k->where('status', 'pending');
                });
            }
        }
        if (! empty($filters['from'])) {
            $q->whereDate('created_at', '>=', (string) $filters['from']);
        }
        if (! empty($filters['to'])) {
            $q->whereDate('created_at', '<=', (string) $filters['to']);
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

    /**
     * Reset a user's password by admin action and revoke all active tokens.
     *
     * @return array{temporary_password: string}
     */
    public function adminResetPassword(User $user, int $adminId, ?\Illuminate\Http\Request $request = null): array
    {
        $temporaryPassword = Str::random(14);
        $user->update([
            'password' => Hash::make($temporaryPassword),
        ]);

        DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->where('tokenable_id', $user->id)
            ->delete();

        $this->audit->log($adminId, 'user.password.reset', $user, [
            'tokens_revoked' => true,
        ], $request);

        return [
            'temporary_password' => $temporaryPassword,
        ];
    }

    public function createAdmin(array $data, int $adminId, ?\Illuminate\Http\Request $request = null): User
    {
        $name = trim(($data['first_name'] ?? '').' '.($data['last_name'] ?? ''));
        $user = User::query()->create([
            'name' => $name !== '' ? $name : ($data['email'] ?? 'Admin'),
            'first_name' => $data['first_name'] ?? null,
            'last_name' => $data['last_name'] ?? null,
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'is_admin' => true,
            'account_status' => 'active',
        ]);

        $this->audit->log($adminId, 'admin.create', $user, [
            'email' => $user->email,
        ], $request);

        return $user->fresh();
    }

    public function deleteAdmin(User $user, int $adminId, ?\Illuminate\Http\Request $request = null): void
    {
        DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->where('tokenable_id', $user->id)
            ->delete();
        $this->audit->log($adminId, 'admin.delete', $user, [
            'email' => $user->email,
        ], $request);
        $user->delete();
    }
}
