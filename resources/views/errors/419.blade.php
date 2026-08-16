<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta http-equiv="refresh" content="0;url={{ route('filament.app.auth.login') }}">
    <title>{{ __('Page Expired') }}</title>
</head>
<body>
    <script nonce="{{ Vite::cspNonce() }}">
        window.location.href = "{{ route('filament.app.auth.login') }}";
    </script>
    <p>{{ __('Your session has expired. Redirecting to login...') }}</p>
</body>
</html>
