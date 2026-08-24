<div class="space-y-4 text-sm">
    <dl class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div><dt class="font-medium">Status</dt><dd>{{ $exception->status->getLabel() }}</dd></div>
        <div><dt class="font-medium">Evidence boundary</dt><dd>{{ $exception->governance_fingerprint ? 'Governed' : 'Legacy / ungoverned' }}</dd></div>
        <div><dt class="font-medium">Requested by</dt><dd>{{ $exception->requester?->name }}</dd></div>
        <div><dt class="font-medium">Submitted at</dt><dd>{{ $exception->submitted_at }}</dd></div>
        <div><dt class="font-medium">Effective date</dt><dd>{{ $exception->effective_date?->toDateString() }}</dd></div>
        <div><dt class="font-medium">Expiration date</dt><dd>{{ $exception->expiration_date?->toDateString() }}</dd></div>
        <div><dt class="font-medium">Review frequency</dt><dd>{{ $exception->review_frequency_days ? $exception->review_frequency_days.' days' : 'Not governed' }}</dd></div>
        <div><dt class="font-medium">Next monitoring review</dt><dd>{{ $exception->next_review_at }}</dd></div>
        @foreach (['description' => 'Description', 'justification' => 'Justification', 'risk_assessment' => 'Risk assessment', 'compensating_controls' => 'Compensating controls'] as $field => $label)
            <div class="sm:col-span-2"><dt class="font-medium">{{ $label }}</dt><dd class="whitespace-pre-wrap">{{ $exception->{$field} }}</dd></div>
        @endforeach
        @if ($exception->governance_fingerprint)
            <div class="sm:col-span-2"><dt class="font-medium">Request fingerprint</dt><dd class="break-all font-mono">{{ $exception->governance_fingerprint }}</dd></div>
        @endif
    </dl>
    @if ($exception->governance_snapshot)
        <div><h4 class="font-medium">Immutable request snapshot</h4><pre class="mt-2 max-h-80 overflow-auto whitespace-pre-wrap rounded-lg border p-3 text-xs">{{ json_encode($exception->governance_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></div>
    @endif
    @foreach ($exception->decisions as $decision)
        <div class="rounded-lg border p-3">
            <div class="font-medium">Decision v{{ $decision->version }} — {{ $decision->decision->getLabel() }}</div>
            <div>{{ $decision->decider?->name }} · {{ $decision->decided_at }}</div>
            <p class="mt-2 whitespace-pre-wrap">{{ $decision->decision_summary }}</p>
            <div class="mt-2 break-all font-mono text-xs">{{ $decision->fingerprint }}</div>
        </div>
    @endforeach
    @foreach ($exception->monitoringReviews->sortByDesc('version') as $review)
        <div class="rounded-lg border p-3">
            <div class="font-medium">Monitoring review v{{ $review->version }} — {{ $review->outcome->getLabel() }}</div>
            <div>{{ $review->reviewer?->name }} · {{ $review->reviewed_at }}</div>
            <p class="mt-2 whitespace-pre-wrap">{{ $review->review_summary }}</p>
            <p class="mt-2 whitespace-pre-wrap">{{ $review->control_effectiveness }}</p>
            @if ($review->evidence_reference)<div class="mt-2">Operator-supplied reference: {{ $review->evidence_reference }}</div>@endif
            @foreach ($review->evidence as $evidence)
                <div class="mt-2 border-t pt-2">
                    <a class="font-medium text-primary-600 hover:underline" href="{{ route('policy-exception-monitoring-review-evidence.download', $evidence) }}">
                        {{ $evidence->file_name_snapshot }}
                    </a>
                    <div>{{ number_format($evidence->file_size_snapshot) }} bytes · Audit {{ $evidence->audit_id_snapshot }} · SHA-256 <span class="break-all font-mono text-xs">{{ $evidence->sha256 }}</span></div>
                </div>
            @endforeach
            @if ($review->issue)<div class="mt-2">Governed issue: {{ $review->issue->status->getLabel() }} · {{ $review->issue->severity }}</div>@endif
            <div class="mt-2 break-all font-mono text-xs">{{ $review->fingerprint }}</div>
        </div>
    @endforeach
</div>
