<?php

namespace App\Identity;

use RuntimeException;

class IdentityException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 401,
    ) {
        parent::__construct($message);
    }

    public static function invalidState(): self
    {
        return new self('This sign-in link is invalid or has expired.', 401);
    }

    public static function exchangeFailed(): self
    {
        return new self('Sign-in with the identity provider failed.', 401);
    }

    public static function emailUnverified(): self
    {
        return new self('The identity provider did not verify this email address.', 401);
    }

    public static function localAccountExists(): self
    {
        return new self('An account with this email already exists and must sign in locally (break-glass).', 401);
    }

    public static function notConfigured(): self
    {
        return new self('Central OIDC is not configured.', 404);
    }

    public static function noAccount(): self
    {
        return new self('No account exists for this identity and auto-provisioning is disabled.', 401);
    }

    public static function inactive(): self
    {
        return new self('This account has been deactivated.', 403);
    }

    public static function ssoOnly(): self
    {
        return new self('This account must sign in through Fynix HQ (OIDC).', 401);
    }

    public static function passwordDisabled(): self
    {
        return new self('This account signs in through SSO; its password is managed by the identity provider.', 401);
    }
}
