<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSwaggerDocsOtpSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->sessionValid($request)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Swagger documentation requires OTP login. Open /docs/login in a browser.',
            ], 401);
        }

        return redirect()->guest(route('swagger.docs.login'));
    }

    protected function sessionValid(Request $request): bool
    {
        $verified = $request->session()->get('swagger_docs_verified_email');
        $at = $request->session()->get('swagger_docs_verified_at');
        if (! is_string($verified) || ! $at) {
            return false;
        }

        $allowed = array_map('strtolower', (array) config('swagger_docs.allowed_emails', []));
        if (! in_array(strtolower($verified), $allowed, true)) {
            return false;
        }

        $hours = (int) config('swagger_docs.session_ttl_hours', 8);
        try {
            $ts = \Carbon\Carbon::parse($at);
        } catch (\Throwable) {
            return false;
        }

        return $ts->addHours($hours)->isFuture();
    }
}
