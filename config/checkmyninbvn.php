<?php

return [
    'api_key' => env('CHECKMYNINBVN_API_KEY'),
    'base_url' => rtrim(env('CHECKMYNINBVN_BASE_URL', 'https://checkmyninbvn.com.ng/api'), '/'),
    'timeout' => (int) env('CHECKMYNINBVN_HTTP_TIMEOUT', 60),
];
