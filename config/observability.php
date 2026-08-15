<?php

return [
    'dsn' => env('SENTRY_DSN'),
    'environment' => env('SENTRY_ENVIRONMENT', env('APP_ENV', 'production')),
    'release' => env('SENTRY_RELEASE'),
    'timeout_seconds' => (float) env('SENTRY_TIMEOUT_SECONDS', 2),
];
