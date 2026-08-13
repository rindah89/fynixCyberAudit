<?php

$secrets = env('SUITE_PPM_WEBHOOK_SECRETS', '');

return [
    'itsm' => [
        'enabled' => filter_var(env('SUITE_ITSM_ENABLED', env('FYNIX_ITSM_ENABLED', false)), FILTER_VALIDATE_BOOL),
        'base_url' => env('SUITE_ITSM_BASE_URL', env('FYNIX_ITSM_BASE_URL')),
        'token' => env('SUITE_ITSM_API_TOKEN', env('FYNIX_ITSM_API_TOKEN')),
        'company_id' => env('SUITE_ITSM_COMPANY_ID', env('FYNIX_ITSM_COMPANY_ID')),
        'origin_id' => env('SUITE_ITSM_ORIGIN_ID', env('FYNIX_ITSM_ORIGIN_ID')),
        'ticket_type_id' => env('SUITE_ITSM_TICKET_TYPE_ID', env('FYNIX_ITSM_TICKET_TYPE_ID')),
        'department_id' => env('SUITE_ITSM_DEPARTMENT_ID', env('FYNIX_ITSM_DEPARTMENT_ID')),
        'priority_id' => env('SUITE_ITSM_PRIORITY_ID', env('FYNIX_ITSM_PRIORITY_ID')),
        'sync_analyst_id' => env('SUITE_ITSM_SYNC_ANALYST_ID', env('FYNIX_ITSM_SYNC_ANALYST_ID')),
        'requester_email' => env('SUITE_ITSM_REQUESTER_EMAIL', env('FYNIX_GRC_REQUESTER_EMAIL', 'grc-integration@example.invalid')),
        'public_url' => env('SUITE_ITSM_PUBLIC_URL', env('FYNIX_ITSM_PUBLIC_URL')),
        'grc_public_url' => env('SUITE_GRC_PUBLIC_URL', env('FYNIX_GRC_PUBLIC_URL', env('APP_URL'))),
        'webhook_id' => env('SUITE_ITSM_WEBHOOK_ID'),
        'webhook_secret' => env('SUITE_ITSM_WEBHOOK_SECRET'),
        'replay_tolerance' => (int) env('SUITE_ITSM_REPLAY_TOLERANCE', 21600),
    ],
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
