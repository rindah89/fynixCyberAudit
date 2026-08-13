@php
    $oidc = \App\Identity\OidcConfig::resolve();
@endphp

@if (session('oidc_error'))
    <div class="ppm-auth__alert mb-4" role="alert">
        {{ session('oidc_error') }}
    </div>
@endif

@if ($oidc->isReady())
    <div class="mb-6 space-y-3">
        <x-filament::button
            tag="a"
            href="{{ route('auth.sso.login') }}"
            color="primary"
            class="w-full justify-center"
        >
            Sign in with Fynix HQ
        </x-filament::button>
        @unless ($oidc->enforceSsoOnly)
            <p class="text-center text-xs" style="color: var(--gray-500);">or use a local break-glass account</p>
        @endunless
    </div>
@endif
