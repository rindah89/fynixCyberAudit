<div class="space-y-4 text-sm">
    <dl class="grid gap-2 sm:grid-cols-2">
        <div><dt class="font-medium">{{ __('Title') }}</dt><dd>{{ $milestone->title }}</dd></div>
        <div><dt class="font-medium">{{ __('Due at') }}</dt><dd>{{ $milestone->due_at?->toIso8601String() }}</dd></div>
        <div><dt class="font-medium">{{ __('Owner') }}</dt><dd>{{ $milestone->owner?->name }}</dd></div>
        <div><dt class="font-medium">{{ __('Fingerprint') }}</dt><dd class="break-all">{{ $milestone->fingerprint }}</dd></div>
    </dl>
    <section>
        <h3 class="font-medium">{{ __('Lifecycle evidence') }}</h3>
        @foreach ($milestone->events as $event)
            <div class="mt-2 rounded-lg border p-3">
                <div>{{ $event->event_type }} · {{ $event->recorded_at?->toIso8601String() }}</div>
                <div class="break-all">{{ $event->fingerprint }}</div>
            </div>
        @endforeach
    </section>
    <section>
        <h3 class="font-medium">{{ __('Database delivery evidence') }}</h3>
        @foreach ($milestone->deliveries as $delivery)
            <div class="mt-2 rounded-lg border p-3">
                <div>{{ $delivery->event_type }} · {{ $delivery->channel }} · {{ $delivery->recipient?->name }}</div>
                <div>{{ $delivery->notification_id }} · {{ $delivery->delivered_at?->toIso8601String() }}</div>
                <div class="break-all">{{ $delivery->fingerprint }}</div>
                <pre class="mt-2 whitespace-pre-wrap">{{ json_encode($delivery->milestone_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            </div>
        @endforeach
    </section>
</div>
