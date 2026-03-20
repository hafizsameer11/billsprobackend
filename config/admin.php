<?php

return [
    /*
    | Allow POST admin webhook replay routes - dangerous in production; keep false unless debugging.
    */
    'webhook_replay_enabled' => (bool) env('ADMIN_WEBHOOK_REPLAY_ENABLED', false),
];
