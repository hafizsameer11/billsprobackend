<?php

namespace App\Services\Admin;

use App\Models\AdminAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AdminAuditService
{
    public function log(int $adminUserId, string $action, ?Model $subject = null, array $payload = [], ?Request $request = null): AdminAuditLog
    {
        return AdminAuditLog::query()->create([
            'admin_user_id' => $adminUserId,
            'action' => $action,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'payload' => $payload !== [] ? $payload : null,
            'ip_address' => $request?->ip(),
        ]);
    }
}
