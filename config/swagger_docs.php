<?php

return [

    'allowed_emails' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('SWAGGER_DOCS_ALLOWED_EMAILS', 'hmstech11@gmail.com'))
    ))),

    'session_ttl_hours' => (int) env('SWAGGER_DOCS_SESSION_HOURS', 8),

    'otp_ttl_minutes' => (int) env('SWAGGER_DOCS_OTP_TTL', 15),

];
