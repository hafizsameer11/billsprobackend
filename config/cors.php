<?php

$fromEnv = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))
)));

foreach (['FRONTEND_URL', 'APP_URL'] as $urlKey) {
    $url = trim((string) env($urlKey, ''));
    if ($url !== '' && ! in_array($url, $fromEnv, true)) {
        $fromEnv[] = $url;
    }
}

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    /*
    | When CORS_ALLOWED_ORIGINS is empty, allow all origins (mobile native apps
    | ignore CORS; this fixes browser admin / Swagger). Set explicit origins in
    | production if you want to lock down: CORS_ALLOWED_ORIGINS=https://admin.example.com
    */
    'allowed_origins' => $fromEnv !== [] ? $fromEnv : ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
