<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8"/>
    <meta name="application-name" content="{{ config('app.name') }}"/>
    <meta name="csrf-token" content="{{ csrf_token() }}"/>
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link rel="icon" href="{{ asset('img/logo_icon.png') }}"/>
    <title>@yield('title', config('app.name'))</title>
    @vite('resources/css/app.css')
    @stack('head')
</head>
<body>
<main class="ppm-auth">
    <div class="ppm-auth__card @yield('card_class')">
        <div class="ppm-auth__brand">
            <img
                class="ppm-brand-logo"
                src="{{ asset('img/fynix_logo_dark.png') }}"
                alt="{{ config('app.name') }}"
            >
            @hasSection('heading')
                <h1>@yield('heading')</h1>
            @endif
            @hasSection('lede')
                <p>@yield('lede')</p>
            @endif
        </div>
        @yield('content')
    </div>
</main>
@stack('scripts')
</body>
</html>
