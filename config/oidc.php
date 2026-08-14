<?php

return [
    /*
    | Central OIDC (Fynix HQ). Settings keys under auth.oidc.* override
    | these env defaults when the settings table is available.
    */
    'enabled' => (bool) env('OIDC_ENABLED', false),
    'issuer' => env('OIDC_ISSUER'),
    'client_id' => env('OIDC_CLIENT_ID'),
    'client_secret' => env('OIDC_CLIENT_SECRET'),
    'redirect_uri' => env('OIDC_REDIRECT_URI'),
    'authorize_endpoint' => env('OIDC_AUTHORIZE_ENDPOINT'),
    'token_endpoint' => env('OIDC_TOKEN_ENDPOINT'),
    'scopes' => array_values(array_filter(explode(' ', (string) env('OIDC_SCOPES', 'openid email profile')))),
    'auto_provision' => (bool) env('OIDC_AUTO_PROVISION', true),
    'enforce_sso_only' => (bool) env('OIDC_ENFORCE_SSO_ONLY', false),
    'default_role' => env('OIDC_DEFAULT_ROLE', 'Regular User'),
];
