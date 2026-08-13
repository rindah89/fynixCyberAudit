<?php

namespace App\Identity;

use App\Models\User;

final readonly class SsoLoginResult
{
    /**
     * @param  list<string>  $idpGroups
     */
    public function __construct(
        public User $user,
        public bool $newlyProvisioned,
        public array $idpGroups = [],
    ) {}
}
