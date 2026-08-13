@extends('layouts.trust')

@section('title', ($companyName ? $companyName . ' ' : '') . $trustCenterName)

@section('content')
    <header class="ppm-trust-header">
        <div class="ppm-trust-wrap ppm-trust-header__inner">
            @php
                $customLogo = setting('report.logo');
                $logoUrl = $customLogo ? asset('storage/' . $customLogo) : asset('img/fynix_logo_dark.png');
            @endphp
            <img src="{{ $logoUrl }}" alt="{{ $companyName ?: $trustCenterName }}" class="ppm-brand-logo" style="margin-inline: 0; height: 36px;">
            <div>
                @if($companyName)
                    <h1 class="ppm-page-head__title">{{ $companyName }}</h1>
                    <p style="color: var(--gray-500);">{{ $trustCenterName }}</p>
                @else
                    <h1 class="ppm-page-head__title">{{ $trustCenterName }}</h1>
                @endif
            </div>
        </div>
    </header>

    @if(session('success'))
        <div class="ppm-trust-wrap" style="margin-top: 16px;">
            <div class="ppm-auth__alert ppm-auth__alert--success">{{ session('success') }}</div>
        </div>
    @endif

    @if(session('error'))
        <div class="ppm-trust-wrap" style="margin-top: 16px;">
            <div class="ppm-auth__alert">{{ session('error') }}</div>
        </div>
    @endif

    <main class="ppm-trust-wrap" style="padding-top: 32px; padding-bottom: 48px;">
        @if(isset($contentBlocks['overview']) && $contentBlocks['overview']->is_enabled)
            <section class="ppm-card" style="margin-bottom: 32px;">
                <h2 class="ppm-card__title">{{ $contentBlocks['overview']->title }}</h2>
                <div class="prose max-w-none" style="margin-top: 12px;">{!! $contentBlocks['overview']->content !!}</div>
            </section>
        @endif

        @if($certifications->count() > 0)
            <section style="margin-bottom: 32px;">
                <h2 class="ppm-card__title" style="margin-bottom: 16px;">Certifications & Compliance</h2>
                <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4">
                    @foreach($certifications as $certification)
                        <div class="ppm-card" style="padding: 16px; text-align: center;">
                            <div class="ppm-brand-mark" style="margin: 0 auto 12px;">
                                <img src="{{ asset('img/logo_bird_dark.png') }}" alt="">
                            </div>
                            <h3 style="font-size: var(--text-small); font-weight: 600;">{{ $certification->name }}</h3>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        <section style="margin-bottom: 32px;" x-data="{ showRequestModal: false, selectedDocuments: [] }">
            <h2 class="ppm-card__title" style="margin-bottom: 16px;">Security Documentation</h2>

            @if($publicDocuments->count() > 0)
                <div style="margin-bottom: 32px;">
                    <h3 class="ppm-section-title" style="margin-bottom: 12px; font-weight: 600; color: var(--gray-700);">
                        Public Documents
                    </h3>
                    <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                        @foreach($publicDocuments as $document)
                            <div class="ppm-card" style="padding: 20px;">
                                <h4 style="font-weight: 700;">{{ $document->name }}</h4>
                                @if($document->description)
                                    <p style="color: var(--gray-500); font-size: var(--text-small); margin-top: 6px;">{{ Str::limit($document->description, 100) }}</p>
                                @endif
                                @if($document->certifications->count() > 0)
                                    <div style="display: flex; flex-wrap: wrap; gap: 6px; margin-top: 10px;">
                                        @foreach($document->certifications as $cert)
                                            <span class="ppm-chip ppm-chip--blue">{{ $cert->name }}</span>
                                        @endforeach
                                    </div>
                                @endif
                                <a href="{{ route('trust-center.document.download', $document) }}" class="ppm-btn ppm-btn--secondary ppm-btn--sm" style="margin-top: 14px;">
                                    Download
                                </a>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            @if($protectedDocuments->count() > 0)
                <div id="protected-documents">
                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 12px; flex-wrap: wrap;">
                        <h3 style="font-weight: 600; color: var(--gray-700);">
                            Protected Documents
                            <span style="font-weight: 400; color: var(--gray-500); font-size: var(--text-small);"> (Requires access request)</span>
                        </h3>
                        <button
                            type="button"
                            @click="selectedDocuments = [{{ $protectedDocuments->pluck('id')->implode(', ') }}]; showRequestModal = true"
                            class="ppm-btn ppm-btn--primary ppm-btn--sm"
                        >
                            Request Access to All
                        </button>
                    </div>
                    <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                        @foreach($protectedDocuments as $document)
                            <div class="ppm-card" style="padding: 20px;">
                                <h4 style="font-weight: 700;">{{ $document->name }}</h4>
                                @if($document->description)
                                    <p style="color: var(--gray-500); font-size: var(--text-small); margin-top: 6px;">{{ Str::limit($document->description, 100) }}</p>
                                @endif
                                @if($document->certifications->count() > 0)
                                    <div style="display: flex; flex-wrap: wrap; gap: 6px; margin-top: 10px;">
                                        @foreach($document->certifications as $cert)
                                            <span class="ppm-chip ppm-chip--blue">{{ $cert->name }}</span>
                                        @endforeach
                                    </div>
                                @endif
                                <button
                                    type="button"
                                    @click="if (!selectedDocuments.includes({{ $document->id }})) { selectedDocuments.push({{ $document->id }}) }; showRequestModal = true"
                                    class="ppm-btn ppm-btn--secondary ppm-btn--sm"
                                    style="margin-top: 14px;"
                                >
                                    Request Access
                                </button>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div x-show="showRequestModal" x-cloak class="ppm-modal" role="dialog" aria-modal="true" aria-labelledby="modal-title">
                    <div class="ppm-modal__backdrop" @click="showRequestModal = false"></div>
                    <div class="ppm-card ppm-modal__panel">
                        <form action="{{ route('trust-center.request-access') }}" method="POST">
                            @csrf
                            <div class="absolute -left-[9999px]" aria-hidden="true">
                                <label for="website_url">Leave this field empty</label>
                                <input type="text" name="website_url" id="website_url" tabindex="-1" autocomplete="off">
                            </div>
                            <h3 id="modal-title" class="ppm-card__title">Request Document Access</h3>
                            <p style="color: var(--gray-500); font-size: var(--text-small); margin-top: 8px;">
                                Please provide your information to request access to protected documents.
                            </p>

                            <div style="display: flex; flex-direction: column; gap: 14px; margin-top: 20px;">
                                <div class="ppm-field">
                                    <label for="requester_name" class="ppm-field__label">Full Name *</label>
                                    <input type="text" name="requester_name" id="requester_name" required value="{{ old('requester_name') }}" class="ppm-input">
                                    @error('requester_name')
                                        <p class="ppm-field__error">{{ $message }}</p>
                                    @enderror
                                </div>
                                <div class="ppm-field">
                                    <label for="requester_email" class="ppm-field__label">Email Address *</label>
                                    <input type="email" name="requester_email" id="requester_email" required value="{{ old('requester_email') }}" class="ppm-input">
                                    @error('requester_email')
                                        <p class="ppm-field__error">{{ $message }}</p>
                                    @enderror
                                </div>
                                <div class="ppm-field">
                                    <label for="requester_company" class="ppm-field__label">Company *</label>
                                    <input type="text" name="requester_company" id="requester_company" required value="{{ old('requester_company') }}" class="ppm-input">
                                    @error('requester_company')
                                        <p class="ppm-field__error">{{ $message }}</p>
                                    @enderror
                                </div>
                                <div class="ppm-field">
                                    <label for="reason" class="ppm-field__label">Reason for Access</label>
                                    <textarea name="reason" id="reason" rows="3" class="ppm-textarea">{{ old('reason') }}</textarea>
                                    @error('reason')
                                        <p class="ppm-field__error">{{ $message }}</p>
                                    @enderror
                                </div>
                                <div class="ppm-field">
                                    <span class="ppm-field__label">Select Documents</span>
                                    <div style="border: 1px solid var(--gray-200); border-radius: var(--radius-sm); padding: 12px; max-height: 160px; overflow: auto;">
                                        @foreach($protectedDocuments as $document)
                                            <label style="display: flex; align-items: center; gap: 8px; font-size: var(--text-small); margin-bottom: 8px;">
                                                <input type="checkbox" name="document_ids[]" value="{{ $document->id }}"
                                                    :checked="selectedDocuments.includes({{ $document->id }})">
                                                <span>{{ $document->name }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                    @error('document_ids')
                                        <p class="ppm-field__error">{{ $message }}</p>
                                    @enderror
                                </div>

                                @if($ndaRequired && $ndaText)
                                    <div style="background: var(--gray-50); border-radius: var(--radius-md); padding: 16px;">
                                        <h4 style="font-size: var(--text-small); font-weight: 700;">Non-Disclosure Agreement</h4>
                                        <div style="font-size: var(--text-small); color: var(--gray-700); max-height: 128px; overflow: auto; margin-top: 8px;">
                                            {!! $ndaText !!}
                                        </div>
                                        <label style="display: flex; align-items: center; gap: 8px; margin-top: 12px; font-size: var(--text-small);">
                                            <input type="checkbox" name="nda_agreed" value="1" required>
                                            <span>I agree to the terms above *</span>
                                        </label>
                                        @error('nda_agreed')
                                            <p class="ppm-field__error">{{ $message }}</p>
                                        @enderror
                                    </div>
                                @else
                                    <input type="hidden" name="nda_agreed" value="1">
                                @endif
                            </div>

                            <div style="display: flex; justify-content: flex-end; gap: 8px; margin-top: 20px;">
                                <button type="button" @click="showRequestModal = false; selectedDocuments = []" class="ppm-btn ppm-btn--secondary">Cancel</button>
                                <button type="submit" class="ppm-btn ppm-btn--primary">Submit Request</button>
                            </div>
                        </form>
                    </div>
                </div>
            @endif
        </section>

        @php
            $additionalBlocks = $contentBlocks->filter(fn($block) => $block->slug !== 'overview' && $block->is_enabled)->sortBy('sort_order');
        @endphp
        @if($additionalBlocks->count() > 0)
            <section class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                @foreach($additionalBlocks as $block)
                    <div class="ppm-card">
                        <h2 class="ppm-card__title">{{ $block->title }}</h2>
                        <div class="prose max-w-none" style="margin-top: 12px;">{!! $block->content !!}</div>
                    </div>
                @endforeach
            </section>
        @endif
    </main>

    <footer class="ppm-trust-footer">
        <div class="ppm-trust-wrap" style="text-align: center; color: var(--gray-500); font-size: var(--text-small);">
            <p>&copy; {{ date('Y') }} {{ $companyName ?: config('app.name') }}. All rights reserved.</p>
            <p style="margin-top: 4px;">Powered by Fynix Cyber Audit</p>
        </div>
    </footer>
@endsection
