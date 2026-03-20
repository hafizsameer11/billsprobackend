<?php

namespace App\Http\Controllers;

use App\Mail\SwaggerDocsOtpMail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

class SwaggerDocsAuthController extends Controller
{
    public function showForm(): View
    {
        return view('docs.swagger-login');
    }

    public function requestOtp(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => 'required|email',
        ]);

        $email = strtolower(trim($data['email']));
        $allowed = array_map('strtolower', (array) config('swagger_docs.allowed_emails', []));

        if (! in_array($email, $allowed, true)) {
            return back()->with('status', 'If this email is authorized, a code has been sent.');
        }

        $code = (string) random_int(100_000, 999_999);
        $ttl = (int) config('swagger_docs.otp_ttl_minutes', 15);
        Cache::put('swagger_docs_otp:'.hash('sha256', $email), $code, now()->addMinutes($ttl));

        Mail::to($email)->send(new SwaggerDocsOtpMail($code));

        $request->session()->put('swagger_docs_pending_email', $email);

        return back()->with('status', 'If this email is authorized, a code has been sent.');
    }

    public function verifyOtp(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => 'required|email',
            'code' => 'required|digits:6',
        ]);

        $email = strtolower(trim($data['email']));
        $key = 'swagger_docs_otp:'.hash('sha256', $email);
        $expected = Cache::get($key);

        if ($expected === null || (string) $expected !== $data['code']) {
            return back()->withErrors(['code' => 'Invalid or expired code.']);
        }

        Cache::forget($key);

        $request->session()->put('swagger_docs_verified_email', $email);
        $request->session()->put('swagger_docs_verified_at', now()->toIso8601String());
        $request->session()->forget('swagger_docs_pending_email');

        return redirect('/api/documentation');
    }
}
