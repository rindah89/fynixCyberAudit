<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Authorize Application - {{ config('app.name', 'MCP Server') }}</title>
    <link rel="icon" href="{{ asset('img/logo_icon.png') }}"/>
    @vite('resources/css/app.css')
</head>
<body>
<main class="ppm-auth">
    <div class="ppm-auth__card">
        <div class="ppm-auth__brand">
            <img class="ppm-brand-logo" src="{{ asset('img/fynix_logo_dark.png') }}" alt="{{ config('app.name') }}">
            <h1>Authorize {{ $client->name }}</h1>
            <p>This application will be able to use available MCP functionality.</p>
        </div>

        <div class="ppm-card" style="padding: 16px; box-shadow: none;">
            <div class="ppm-field__label">Logged in as</div>
            <div style="font-weight: 700;">{{ $user->email }}</div>
        </div>

        @if(count($scopes) > 0)
            <div>
                <div class="ppm-field__label" style="margin-bottom: 8px;">Permissions</div>
                @foreach($scopes as $scope)
                    <div class="ppm-chip ppm-chip--blue" style="margin-bottom: 6px;">{{ $scope->description }}</div>
                @endforeach
            </div>
        @endif

        <div style="display: flex; gap: 12px;">
            <form method="POST" action="{{ route('passport.authorizations.deny') }}" style="flex: 1;">
                @csrf
                @method('DELETE')
                <input type="hidden" name="state" value="">
                <input type="hidden" name="client_id" value="{{ $client->id }}">
                <input type="hidden" name="auth_token" value="{{ $authToken }}">
                <button type="submit" class="ppm-btn ppm-btn--secondary" style="width: 100%;">Cancel</button>
            </form>

            <form method="POST" action="{{ route('passport.authorizations.approve') }}" style="flex: 1;" id="authorizeForm">
                @csrf
                <input type="hidden" name="state" value="">
                <input type="hidden" name="client_id" value="{{ $client->id }}">
                <input type="hidden" name="auth_token" value="{{ $authToken }}">
                <button type="submit" class="ppm-btn ppm-btn--primary" style="width: 100%;" id="authorizeButton">
                    <span id="authorizeText">Authorize</span>
                </button>
            </form>
        </div>
    </div>
</main>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const form = document.getElementById('authorizeForm');
        const button = document.getElementById('authorizeButton');
        const authorizeText = document.getElementById('authorizeText');

        form.addEventListener('submit', function () {
            button.disabled = true;
            authorizeText.textContent = 'Authorizing...';
        });
    });
</script>
</body>
</html>
