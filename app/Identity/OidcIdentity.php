<?php

namespace App\Identity;

final readonly class OidcIdentity
{
    /**
     * @param  list<string>  $groups
     */
    public function __construct(
        public string $subject,
        public string $email,
        public string $issuer,
        public bool $emailVerified = false,
        public array $groups = [],
        public ?string $name = null,
    ) {}
}
