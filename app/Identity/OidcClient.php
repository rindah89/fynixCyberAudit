<?php

namespace App\Identity;

interface OidcClient
{
    public function authorizationUrl(string $state, string $redirectUri): string;

    public function exchangeCode(string $code, string $redirectUri): OidcIdentity;
}
