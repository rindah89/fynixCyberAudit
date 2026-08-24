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
</div>
