<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Mobile force-update policy
    |--------------------------------------------------------------------------
    |
    | Clients call GET /api/app-version and compare their native store version
    | against min_version_*. If the app is older, they show a blocking update
    | screen with the platform store URL.
    |
    | Bump these when you ship a mandatory native build (not for OTA-only fixes).
    |
    */
    'min_version_android' => (string) env('APP_MIN_VERSION_ANDROID', '1.2.0'),
    'min_version_ios' => (string) env('APP_MIN_VERSION_IOS', '1.2.0'),
    'latest_version_android' => (string) env('APP_LATEST_VERSION_ANDROID', '1.2.3'),
    'latest_version_ios' => (string) env('APP_LATEST_VERSION_IOS', '1.2.3'),
    'android_store_url' => (string) env(
        'APP_ANDROID_STORE_URL',
        'https://play.google.com/store/apps/details?id=com.pejul.billspro'
    ),
    'ios_store_url' => (string) env(
        'APP_IOS_STORE_URL',
        'https://apps.apple.com/ng/app/billspro/id6766338288'
    ),
    'force_update_message' => (string) env(
        'APP_FORCE_UPDATE_MESSAGE',
        'A new version of Bills Pro is required to continue. Please update from the store.'
    ),
];
