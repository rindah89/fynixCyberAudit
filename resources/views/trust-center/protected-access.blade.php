@extends('layouts.trust')

@section('title', 'Protected Document Access - Trust Center')

@section('content')
    <main class="ppm-auth">
        <div class="ppm-auth__card ppm-auth__card--wide">
            <div class="ppm-auth__brand">
                <img class="ppm-brand-logo" src="{{ asset('img/fynix_logo_dark.png') }}" alt="{{ config('app.name') }}">
                <h1>Protected document access</h1>
                <p>Hello, {{ $accessRequest->requester_name }}! Your access request has been approved.</p>
            </div>

            <div class="ppm-auth__alert ppm-auth__alert--info">
                Your access expires on <strong>{{ $accessRequest->access_expires_at->format('F j, Y \a\t g:i A') }}</strong>.
                Please download any documents you need before then.
            </div>

            <h3 class="ppm-card__title">Available documents</h3>

            @if($documents->count() > 0)
                <div style="display: flex; flex-direction: column; gap: 12px;">
                    @foreach($documents as $document)
                        <div class="ppm-card" style="padding: 16px; display: flex; align-items: center; justify-content: space-between; gap: 12px;">
                            <div>
                                <h4 style="font-weight: 700;">{{ $document->name }}</h4>
                                @if($document->description)
                                    <p style="color: var(--gray-500); font-size: var(--text-small);">{{ Str::limit($document->description, 80) }}</p>
                                @endif
                                @if($document->file_size)
                                    <p style="color: var(--gray-500); font-size: var(--text-caption); margin-top: 4px;">{{ number_format($document->file_size / 1024, 0) }} KB</p>
                                @endif
                            </div>
                            <a href="{{ URL::temporarySignedRoute('trust-center.protected-download', $accessRequest->access_expires_at, ['accessRequest' => $accessRequest->id, 'document' => $document->id]) }}"
                               class="ppm-btn ppm-btn--primary ppm-btn--sm">
                                Download
                            </a>
                        </div>
                    @endforeach
                </div>
            @else
                <p style="color: var(--gray-500); text-align: center;">No documents are available at this time.</p>
            @endif

            <a href="{{ route('trust-center.index') }}" class="ppm-action-link" style="justify-content: center;">
                Return to Trust Center
            </a>
        </div>
    </main>
@endsection
