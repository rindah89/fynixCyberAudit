<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Identity\IdentityException;
use App\Identity\IdentityService;
use App\Identity\OidcIdentity;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
    public function redirectToProvider(string $provider)
    {
        return Socialite::driver($provider)->redirect();
    }

    public function handleProviderCallback(string $provider, IdentityService $identity)
    {
        $socialiteUser = Socialite::driver($provider)->user();
        $raw = is_array($socialiteUser->user ?? null) ? $socialiteUser->user : [];

        $oidcIdentity = new OidcIdentity(
            subject: (string) $socialiteUser->getId(),
            email: (string) $socialiteUser->getEmail(),
            issuer: (string) ($raw['iss'] ?? $provider),
            emailVerified: (bool) ($raw['email_verified'] ?? false),
            groups: is_array($raw['groups'] ?? null) ? array_map('strval', $raw['groups']) : [],
            name: $socialiteUser->getName(),
        );

        try {
            $result = $identity->complete($oidcIdentity);
        } catch (IdentityException $exception) {
            return redirect()
                ->route('filament.app.auth.login')
                ->with('oidc_error', $exception->getMessage());
        }

        Auth::login($result->user);
        $result->user->updateLastActivity();
        session()->regenerate();

        return redirect()->to('/app');
    }
}
