@extends('layouts.trust')

@section('title', 'Access Expired - Trust Center')

@section('content')
    <main class="ppm-auth">
        <div class="ppm-auth__card">
            <div class="ppm-auth__brand">
                <img class="ppm-brand-logo" src="{{ asset('img/fynix_logo_dark.png') }}" alt="{{ config('app.name') }}">
                <h1>Access expired</h1>
                <p>Your access link has expired or is no longer valid.</p>
            </div>
            <p style="color: var(--gray-700); font-size: var(--text-body);">
                Document access links expire after 24 hours for security purposes.
                If you still need access to these documents, please submit a new access request.
            </p>
            <a href="{{ route('trust-center.index') }}" class="ppm-btn ppm-btn--primary">Return to Trust Center</a>
        </div>
    </main>
@endsection
