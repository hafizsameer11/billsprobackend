<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .container {
            background-color: #f9f9f9;
            border-radius: 8px;
            padding: 30px;
            margin: 20px 0;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        .logo {
            font-size: 24px;
            font-weight: bold;
            color: #2563eb;
            margin-bottom: 10px;
        }
        .otp-box {
            background-color: #ffffff;
            border: 2px dashed #dc2626;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            margin: 30px 0;
        }
        .otp-code {
            font-size: 32px;
            font-weight: bold;
            color: #dc2626;
            letter-spacing: 8px;
            font-family: 'Courier New', monospace;
        }
        .footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
            font-size: 12px;
            color: #666;
            text-align: center;
        }
        .warning {
            background-color: #fee2e2;
            border-left: 4px solid #dc2626;
            padding: 12px;
            margin: 20px 0;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">{{ config('app.name', 'Bill\'s Pro') }}</div>
            <h2>Password Reset Request</h2>
        </div>

        <p>Hello,</p>

        <p>We received a request to reset your password for your {{ config('app.name', 'Bill\'s Pro') }} account. Use the verification code below to proceed:</p>

        <div class="otp-box">
            <div class="otp-code">{{ $otp }}</div>
        </div>

        <p>This code will expire in <strong>{{ $expiresInMinutes }} minutes</strong>.</p>

        <div class="warning">
            <strong>Security Notice:</strong> If you didn't request a password reset, please ignore this email. Your account remains secure.
        </div>

        <p>Never share this code with anyone. Our team will never ask for your verification code.</p>

        <div class="footer">
            <p>This is an automated message, please do not reply to this email.</p>
            <p>&copy; {{ date('Y') }} {{ config('app.name', 'Bill\'s Pro') }}. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
