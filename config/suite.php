<?php

$secrets = env('SUITE_PPM_WEBHOOK_SECRETS', '');

return [
    'ppm' => [
        'enabled' => (bool) env('SUITE_PPM_ENABLED', false),
        'base_url' => env('SUITE_PPM_BASE_URL'),
        'token' => env('SUITE_PPM_TOKEN'),
        'tenant_id' => env('SUITE_PPM_TENANT_ID'),
        'webhook_id' => env('SUITE_PPM_WEBHOOK_ID'),
        'webhook_secrets' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $secrets)
        ))),
        'public_url' => env('SUITE_PPM_PUBLIC_URL', env('SUITE_PPM_BASE_URL')),
        'default_work_type_id' => env('SUITE_PPM_DEFAULT_WORK_TYPE_ID'),
        'replay_tolerance' => (int) env('SUITE_PPM_REPLAY_TOLERANCE', 21600),
    ],
];
