<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Identity\IdentityException;
use App\Identity\IdentityService;
use App\Identity\OidcConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class SsoController extends Controller
{
    public function login(Request $request, IdentityService $identity, OidcConfig $config): RedirectResponse
    {
        if (! $config->isReady()) {
            abort(404, 'Central OIDC is not configured.');
        }

        $state = Str::random(40);
        $request->session()->put('oidc.state', $state);
        $request->session()->put('oidc.redirect', $config->redirectUri);

        return redirect()->away($identity->authorizationUrl($state, $config->redirectUri));
    }

    public function callback(Request $request, IdentityService $identity): RedirectResponse
    {
        $expected = (string) $request->session()->pull('oidc.state', '');
        $state = (string) $request->query('state', '');

        if ($expected === '' || $state === '' || ! hash_equals($expected, $state)) {
            return $this->failed(IdentityException::invalidState());
        }

        if ($request->filled('error')) {
            return $this->failed(IdentityException::exchangeFailed());
        }

        try {
            $result = $identity->exchangeAndComplete(
                (string) $request->query('code'),
                $request->session()->pull('oidc.redirect'),
            );
        } catch (IdentityException $exception) {
            return $this->failed($exception);
        }

        Auth::login($result->user);
        $request->session()->regenerate();
        $result->user->updateLastActivity();

        return redirect()->intended('/app');
    }

    private function failed(IdentityException $exception): RedirectResponse
    {
        return redirect()
            ->route('filament.app.auth.login')
            ->with('oidc_error', $exception->getMessage());
    }
}
