<?php

return [
    'enabled' => env('CYBERAUDIT_AUTHORIZATION_AUDIT_ENABLED', false),
    'fingerprint_key' => env('CYBERAUDIT_AUTHORIZATION_AUDIT_FINGERPRINT_KEY'),
    'spool' => env('CYBERAUDIT_AUTHORIZATION_AUDIT_SPOOL', storage_path('app/support-authorization-audit')),
    'stale_seconds' => (int) env('CYBERAUDIT_AUTHORIZATION_AUDIT_STALE_SECONDS', 300),
];
