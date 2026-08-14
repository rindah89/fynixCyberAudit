<?php

namespace App\Identity;

use Illuminate\Support\Facades\Http;

class RealOidcClient implements OidcClient
{
    public function __construct(
        private readonly string $issuer,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $authorizeEndpoint,
        private readonly string $tokenEndpoint,
        private readonly array $scopes = ['openid', 'email', 'profile'],
    ) {}

    public static function fromConfig(OidcConfig $config): self
    {
        if (! $config->isReady()) {
            throw IdentityException::notConfigured();
        }

        $authorize = $config->authorizeEndpoint;
        $token = $config->tokenEndpoint;

        if (! $authorize || ! $token) {
            $discovered = self::discover((string) $config->issuer);
            $authorize = $authorize ?: $discovered['authorization_endpoint'] ?? null;
            $token = $token ?: $discovered['token_endpoint'] ?? null;
        }

        if (! $authorize || ! $token) {
            throw IdentityException::notConfigured();
        }

        return new self(
            issuer: (string) $config->issuer,
            clientId: (string) $config->clientId,
            clientSecret: (string) $config->clientSecret,
            authorizeEndpoint: $authorize,
            tokenEndpoint: $token,
            scopes: $config->scopes,
        );
    }

    public function authorizationUrl(string $state, string $redirectUri): string
    {
        return $this->authorizeEndpoint.'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri,
            'scope' => implode(' ', $this->scopes),
            'state' => $state,
        ]);
    }

    public function exchangeCode(string $code, string $redirectUri): OidcIdentity
    {
        $response = Http::asForm()->timeout(10)->post($this->tokenEndpoint, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ]);

        if (! $response->successful() || ! $response->json('id_token')) {
            throw IdentityException::exchangeFailed();
        }

        return $this->identityFromIdToken((string) $response->json('id_token'));
    }

    public function identityFromIdToken(string $idToken): OidcIdentity
    {
        $parts = explode('.', $idToken);
        if (count($parts) < 2) {
            throw IdentityException::exchangeFailed();
        }

        $payload = json_decode($this->base64UrlDecode($parts[1]), true);
        if (! is_array($payload)) {
            throw IdentityException::exchangeFailed();
        }

        if ((string) ($payload['iss'] ?? '') !== $this->issuer) {
            throw IdentityException::exchangeFailed();
        }

        $aud = $payload['aud'] ?? [];
        $audValues = is_array($aud) ? $aud : [$aud];
        if (! in_array($this->clientId, array_map('strval', $audValues), true)) {
            throw IdentityException::exchangeFailed();
        }

        $subject = $payload['sub'] ?? null;
        $email = $payload['email'] ?? $payload['preferred_username'] ?? null;
        if (! $subject || ! $email) {
            throw IdentityException::exchangeFailed();
        }

        $groups = $payload['groups'] ?? [];
        if (! is_array($groups)) {
            $groups = [];
        }

        return new OidcIdentity(
            subject: (string) $subject,
            email: (string) $email,
            issuer: $this->issuer,
            emailVerified: (bool) ($payload['email_verified'] ?? false),
            groups: array_values(array_map('strval', $groups)),
            name: isset($payload['name']) ? (string) $payload['name'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function discover(string $issuer): array
    {
        $url = rtrim($issuer, '/').'/.well-known/openid-configuration';
        $response = Http::timeout(10)->get($url);

        return $response->successful() ? $response->json() ?? [] : [];
    }

    private function base64UrlDecode(string $segment): string
    {
        $remainder = strlen($segment) % 4;
        if ($remainder) {
            $segment .= str_repeat('=', 4 - $remainder);
        }

        return (string) base64_decode(strtr($segment, '-_', '+/'), true);
    }
}
