<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" href="{{ asset('img/logo_icon.png') }}"/>
    <title>@yield('title', config('app.name'))</title>
    @vite('resources/css/app.css')
    <script nonce="{{ Vite::cspNonce() }}" defer src="https://cdn.jsdelivr.net/npm/@alpinejs/csp@3.x.x/dist/cdn.min.js"></script>
</head>
<body>
    @yield('content')
</body>
</html>
