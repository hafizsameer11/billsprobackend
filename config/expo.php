<?php

return [
    /**
     * Optional Expo access token for higher rate limits when calling the push API from the server.
     * @see https://docs.expo.dev/push-notifications/sending-notifications/#additional-security
     */
    'access_token' => env('EXPO_ACCESS_TOKEN'),

    'push_api_url' => env('EXPO_PUSH_API_URL', 'https://exp.host/--/api/v2/push/send'),
];
