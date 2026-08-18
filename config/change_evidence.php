<?php

return [
    'executive_origin' => env('CYBERAUDIT_EXECUTIVE_AUTHORITY_ORIGIN', 'https://fynixhq.com'),
    'executive_public_keys_file' => env('CYBERAUDIT_EXECUTIVE_AUTHORITY_PUBLIC_KEYS_FILE'),
    'requester_company_id' => (int) env('CYBERAUDIT_CHANGE_REQUESTER_COMPANY_ID', 0),
    'requester_key_file' => env('CYBERAUDIT_CHANGE_REQUESTER_KEY_FILE'),
    'signing_key_file' => env('CYBERAUDIT_CHANGE_SIGNING_KEY_FILE'),
    'signing_key_id' => env('CYBERAUDIT_CHANGE_SIGNING_KEY_ID'),
    'signing_public_keys_file' => env('CYBERAUDIT_CHANGE_SIGNING_PUBLIC_KEYS_FILE'),
    'ttl_seconds' => (int) env('CYBERAUDIT_CHANGE_ACCEPTANCE_TTL_SECONDS', 600),
];
