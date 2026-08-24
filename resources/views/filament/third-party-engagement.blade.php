<div class="space-y-4 text-sm">
    <dl class="grid grid-cols-1 gap-3 md:grid-cols-3">
        <div><dt class="font-medium">Code</dt><dd>{{ $engagement->code }}</dd></div>
        <div><dt class="font-medium">Status</dt><dd>{{ $engagement->status->getLabel() }}</dd></div>
        <div><dt class="font-medium">Business owner</dt><dd>{{ $engagement->businessOwner?->name }}</dd></div>
        <div><dt class="font-medium">Criticality</dt><dd>{{ ucfirst($engagement->criticality) }}</dd></div>
        <div><dt class="font-medium">Term</dt><dd>{{ $engagement->term_start_at?->toDateString() }} – {{ $engagement->term_end_at?->toDateString() }}</dd></div>
        <div><dt class="font-medium">Next review</dt><dd>{{ $engagement->next_review_at?->toDateString() }}</dd></div>
    </dl>
    <div><div class="font-medium">Service description</div><div class="whitespace-pre-wrap">{{ $engagement->service_description }}</div></div>
    <div class="space-y-2">
        <div class="font-medium">Append-only decisions</div>
        @foreach ($engagement->events as $event)
            <div class="rounded-lg border p-3">
                <div>v{{ $event->version }} · {{ $event->to_status->getLabel() }} · {{ $event->actor?->name }} · {{ $event->recorded_at?->toDayDateTimeString() }}</div>
                <div class="mt-1 whitespace-pre-wrap">{{ $event->summary }}</div>
                <div class="mt-1 break-all font-mono text-xs">{{ $event->fingerprint }}</div>
            </div>
        @endforeach
    </div>
    <div class="space-y-2">
        <div class="font-medium">Contract-risk review history</div>
        @forelse ($engagement->contractRiskReviews->sortBy('version') as $review)
            <div class="rounded-lg border p-3">
                <div>v{{ $review->version }} · {{ $review->decision->getLabel() }} · {{ $review->contract_reference }} · {{ $review->reviewer?->name }}</div>
                <div class="mt-1">{{ $review->agreement_type }} · {{ $review->effective_at?->toDateString() }} – {{ $review->expires_at?->toDateString() }}</div>
                @if ($review->proposed_term_end_at)
                    <div class="mt-1">Proposed renewed term end: {{ $review->proposed_term_end_at->toDateString() }} · next review: {{ $review->proposed_next_review_at?->toDateString() }}</div>
                @endif
                <dl class="mt-2 grid grid-cols-2 gap-2 md:grid-cols-4">
                    <div><dt class="font-medium">Confidentiality</dt><dd>{{ $review->confidentiality_terms ? 'Present' : 'Absent' }}</dd></div>
                    <div><dt class="font-medium">Data protection</dt><dd>{{ $review->data_protection_terms ? 'Present' : 'Absent' }}</dd></div>
                    <div><dt class="font-medium">Incident notification</dt><dd>{{ $review->incident_notification_terms ? 'Present' : 'Absent' }}</dd></div>
                    <div><dt class="font-medium">Audit rights</dt><dd>{{ $review->audit_rights ? 'Present' : 'Absent' }}</dd></div>
                    <div><dt class="font-medium">Subcontractor controls</dt><dd>{{ $review->subcontractor_controls ? 'Present' : 'Absent' }}</dd></div>
                    <div><dt class="font-medium">Business continuity</dt><dd>{{ $review->business_continuity_terms ? 'Present' : 'Absent' }}</dd></div>
                    <div><dt class="font-medium">Termination assistance</dt><dd>{{ $review->termination_assistance ? 'Present' : 'Absent' }}</dd></div>
                </dl>
                <div class="mt-2"><span class="font-medium">Service levels:</span> {{ $review->service_level_summary }}</div>
                <div><span class="font-medium">Liability:</span> {{ $review->liability_summary }}</div>
                <div><span class="font-medium">Exit terms:</span> {{ $review->exit_terms_summary }}</div>
                <div><span class="font-medium">Exceptions:</span> {{ $review->exceptions_summary ?: 'None recorded' }}</div>
                <div><span class="font-medium">Conditions:</span> {{ $review->conditions ?: 'None recorded' }}</div>
                <div class="mt-1 whitespace-pre-wrap">{{ $review->rationale }}</div>
                <div class="mt-2 break-all"><span class="font-medium">Source engagement event:</span> <span class="font-mono text-xs">{{ $review->engagement_event_fingerprint }}</span></div>
                <details class="mt-2"><summary class="font-medium">Engagement snapshot</summary><pre class="mt-1 overflow-auto whitespace-pre-wrap text-xs">{{ json_encode($review->engagement_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre></details>
                <details class="mt-2"><summary class="font-medium">Vendor-risk approval snapshot</summary><pre class="mt-1 overflow-auto whitespace-pre-wrap text-xs">{{ json_encode($review->risk_approval_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre></details>
                <div class="mt-1 break-all font-mono text-xs">{{ $review->fingerprint }}</div>
            </div>
        @empty
            <div>No contract-risk review has been recorded.</div>
        @endforelse
    </div>
</div>
