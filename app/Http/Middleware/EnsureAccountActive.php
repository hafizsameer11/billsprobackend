<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        $status = $user->account_status ?? 'active';
        if ($status === 'active') {
            return $next($request);
        }

        // Allow read-only profile so clients can show suspension state
        if ($request->isMethod('GET') && ($request->is('api/user', 'api/user/profile'))) {
            return $next($request);
        }

        $code = $status === 'banned' ? 'ACCOUNT_BANNED' : 'ACCOUNT_SUSPENDED';

        return response()->json([
            'success' => false,
            'message' => 'Your account is not active.',
            'code' => $code,
            'account_status' => $status,
        ], 403);
    }
}
