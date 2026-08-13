<?php

namespace App\Identity;

use Illuminate\Support\Facades\Crypt;

final readonly class OidcConfig
{
    /**
     * @param  list<string>  $scopes
     */
    public function __construct(
        public bool $enabled,
        public ?string $issuer,
        public ?string $clientId,
        public ?string $clientSecret,
        public string $redirectUri,
        public ?string $authorizeEndpoint,
        public ?string $tokenEndpoint,
        public array $scopes,
        public bool $autoProvision,
        public bool $enforceSsoOnly,
        public ?string $defaultRole,
    ) {}

    public static function resolve(): self
    {
        $secret = self::settingOrConfig('auth.oidc.client_secret', 'oidc.client_secret');
        if (is_string($secret) && $secret !== '' && self::looksEncrypted($secret)) {
            try {
                $secret = Crypt::decryptString($secret);
            } catch (\Throwable) {
                // leave as-is; RealOidcClient will fail closed on exchange
            }
        }

        $redirect = self::settingOrConfig('auth.oidc.redirect_uri', 'oidc.redirect_uri')
            ?: rtrim((string) config('app.url'), '/').'/auth/sso/callback';

        $scopes = self::settingOrConfig('auth.oidc.scopes', 'oidc.scopes') ?? ['openid', 'email', 'profile', 'groups'];
        if (is_string($scopes)) {
            $scopes = array_values(array_filter(explode(' ', $scopes)));
        }

        return new self(
            enabled: (bool) self::settingOrConfig('auth.oidc.enabled', 'oidc.enabled', false),
            issuer: self::nullableString(self::settingOrConfig('auth.oidc.issuer', 'oidc.issuer')),
            clientId: self::nullableString(self::settingOrConfig('auth.oidc.client_id', 'oidc.client_id')),
            clientSecret: self::nullableString($secret),
            redirectUri: (string) $redirect,
            authorizeEndpoint: self::nullableString(self::settingOrConfig('auth.oidc.authorize_endpoint', 'oidc.authorize_endpoint')),
            tokenEndpoint: self::nullableString(self::settingOrConfig('auth.oidc.token_endpoint', 'oidc.token_endpoint')),
            scopes: is_array($scopes) ? $scopes : ['openid', 'email', 'profile'],
            autoProvision: (bool) self::settingOrConfig('auth.oidc.auto_provision', 'oidc.auto_provision', true),
            enforceSsoOnly: (bool) self::settingOrConfig('auth.oidc.enforce_sso_only', 'oidc.enforce_sso_only', false),
            defaultRole: self::nullableString(self::settingOrConfig('auth.oidc.default_role', 'oidc.default_role')),
        );
    }

    public function isReady(): bool
    {
        return $this->enabled && filled($this->issuer) && filled($this->clientId) && filled($this->clientSecret);
    }

    private static function settingOrConfig(string $settingKey, string $configKey, mixed $default = null): mixed
    {
        try {
            if (function_exists('setting')) {
                $value = setting($settingKey);
                if ($value !== null && $value !== '') {
                    return $value;
                }
            }
        } catch (\Throwable) {
            // settings table may be missing during install
        }

        return config($configKey, $default);
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    private static function looksEncrypted(string $value): bool
    {
        return str_starts_with($value, 'eyJ') || str_contains($value, ':');
    }
}
