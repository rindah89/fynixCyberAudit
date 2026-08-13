<?php

namespace App\Identity;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class IdentityService
{
    public function __construct(
        private readonly OidcClient $oidcClient,
        private readonly OidcConfig $config,
    ) {}

    public function authorizationUrl(string $state, ?string $redirectUri = null): string
    {
        if (! $this->config->isReady()) {
            throw IdentityException::notConfigured();
        }

        return $this->oidcClient->authorizationUrl($state, $redirectUri ?? $this->config->redirectUri);
    }

    public function complete(OidcIdentity $identity): SsoLoginResult
    {
        $existingBySubject = User::query()
            ->where('sso_subject', $identity->subject)
            ->first();

        if ($existingBySubject) {
            return new SsoLoginResult(
                user: $this->refreshSsoLogin($existingBySubject, $identity),
                newlyProvisioned: false,
                idpGroups: $identity->groups,
            );
        }

        if (! $identity->emailVerified) {
            throw IdentityException::emailUnverified();
        }

        $existingByEmail = User::query()
            ->whereRaw('lower(email) = ?', [strtolower($identity->email)])
            ->first();

        if ($existingByEmail) {
            if (! $existingByEmail->is_sso) {
                throw IdentityException::localAccountExists();
            }

            return new SsoLoginResult(
                user: $this->refreshSsoLogin($existingByEmail, $identity),
                newlyProvisioned: false,
                idpGroups: $identity->groups,
            );
        }

        if (! $this->config->autoProvision) {
            throw IdentityException::noAccount();
        }

        $user = User::query()->create([
            'name' => $identity->name ?: strstr($identity->email, '@', true) ?: $identity->email,
            'email' => $identity->email,
            'password' => Hash::make(Str::password(32)),
            'email_verified_at' => now(),
            'password_reset_required' => false,
            'is_sso' => true,
            'sso_subject' => $identity->subject,
            'sso_issuer' => $identity->issuer,
        ]);

        $this->assignDefaultRole($user);
        $this->applyGroupRoles($user, $identity->groups);

        return new SsoLoginResult(
            user: $user,
            newlyProvisioned: true,
            idpGroups: $identity->groups,
        );
    }

    public function exchangeAndComplete(string $code, ?string $redirectUri = null): SsoLoginResult
    {
        $identity = $this->oidcClient->exchangeCode($code, $redirectUri ?? $this->config->redirectUri);
        $result = $this->complete($identity);
        $this->applyGroupRoles($result->user, $result->idpGroups);

        return $result;
    }

    public function assertLocalPasswordAllowed(User $user): void
    {
        if ($user->is_sso && ! $user->is_break_glass) {
            throw IdentityException::passwordDisabled();
        }

        if ($this->config->enforceSsoOnly && ! $user->is_break_glass) {
            throw IdentityException::ssoOnly();
        }
    }

    /**
     * @param  list<string>  $groups
     */
    public function applyGroupRoles(User $user, array $groups): void
    {
        $map = setting('auth.oidc.group_role_map');
        if (! is_array($map) || $groups === []) {
            return;
        }

        foreach ($groups as $group) {
            $roleName = $map[$group] ?? null;
            if (! is_string($roleName) || $roleName === '') {
                continue;
            }

            $role = Role::query()->where('name', $roleName)->first();
            if ($role && ! $user->hasRole($role)) {
                $user->assignRole($role);
            }
        }
    }

    private function refreshSsoLogin(User $user, OidcIdentity $identity): User
    {
        if ($user->trashed()) {
            throw IdentityException::inactive();
        }

        $user->forceFill([
            'is_sso' => true,
            'sso_subject' => $identity->subject,
            'sso_issuer' => $identity->issuer,
            'email_verified_at' => $user->email_verified_at ?? now(),
            'password_reset_required' => false,
        ])->save();

        $user->updateLastActivity();

        return $user->refresh();
    }

    private function assignDefaultRole(User $user): void
    {
        $roleName = $this->config->defaultRole;
        if (! $roleName) {
            return;
        }

        $role = Role::query()->where('name', $roleName)->first();
        if ($role) {
            $user->assignRole($role);
        }
    }
}
