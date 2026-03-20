<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>API docs login</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 420px; margin: 3rem auto; padding: 0 1rem; }
        label { display: block; margin-top: 1rem; }
        input { width: 100%; padding: 0.5rem; margin-top: 0.25rem; }
        button { margin-top: 1rem; padding: 0.5rem 1rem; }
        .err { color: #b91c1c; }
        .ok { color: #15803d; }
    </style>
</head>
<body>
<h1>API documentation</h1>
<p>Enter your authorized email to receive a one-time code.</p>

@if(session('status'))
    <p class="ok">{{ session('status') }}</p>
@endif

<form method="post" action="{{ url('/docs/email-otp') }}">
    @csrf
    <label>Email
        <input type="email" name="email" value="{{ old('email', session('swagger_docs_pending_email')) }}" required>
    </label>
    <button type="submit">Send code</button>
</form>

<form method="post" action="{{ url('/docs/verify-otp') }}" style="margin-top:2rem;">
    @csrf
    <label>Email
        <input type="email" name="email" value="{{ old('email', session('swagger_docs_pending_email')) }}" required>
    </label>
    <label>6-digit code
        <input type="text" name="code" inputmode="numeric" maxlength="6" required>
    </label>
    @error('code')<p class="err">{{ $message }}</p>@enderror
    <button type="submit">Verify &amp; continue</button>
</form>
</body>
</html>
