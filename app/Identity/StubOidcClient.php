<?php

namespace App\Identity;

class StubOidcClient implements OidcClient
{
    /** @var array<string, OidcIdentity> */
    private array $identities = [];

    /** @var list<string> */
    public array $requestedStates = [];

    /** @var list<string> */
    public array $requestedRedirectUris = [];

    public function __construct(
        private readonly string $authorizeBase = 'https://stub-idp.test/authorize',
    ) {}

    public function register(string $code, OidcIdentity $identity): void
    {
        $this->identities[$code] = $identity;
    }

    public function authorizationUrl(string $state, string $redirectUri): string
    {
        $this->requestedStates[] = $state;
        $this->requestedRedirectUris[] = $redirectUri;

        return $this->authorizeBase.'?'.http_build_query([
            'state' => $state,
            'redirect_uri' => $redirectUri,
        ]);
    }

    public function exchangeCode(string $code, string $redirectUri): OidcIdentity
    {
        $identity = $this->identities[$code] ?? null;
        if ($identity === null) {
            throw IdentityException::exchangeFailed();
        }

        return $identity;
    }
}
