<div class="space-y-4 text-sm">
    @foreach ($notification->events as $event)
        <section class="rounded-lg border border-gray-200 bg-white p-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <strong>Version {{ $event->version }} · {{ __($event->event_type === 'registered' ? 'Registered' : 'Decision') }}</strong>
                <span>{{ $event->recorded_at?->format('Y-m-d H:i:s') }} · {{ $event->actor?->name }}</span>
            </div>
            <p class="mt-2">{{ $event->rationale }}</p>
            <dl class="mt-3 grid gap-2 md:grid-cols-2">
                <div><dt class="font-medium">Audience</dt><dd>{{ data_get($event->after_snapshot, 'audience') }}</dd></div>
                <div><dt class="font-medium">Status</dt><dd>{{ data_get($event->after_snapshot, 'status') }}</dd></div>
                <div><dt class="font-medium">Framework</dt><dd>{{ data_get($event->after_snapshot, 'framework', __('Not specified')) }}</dd></div>
                <div><dt class="font-medium">Recipient</dt><dd>{{ data_get($event->after_snapshot, 'recipient') }}</dd></div>
                <div><dt class="font-medium">Deadline</dt><dd>{{ data_get($event->after_snapshot, 'deadline_at', __('None')) }}</dd></div>
                <div><dt class="font-medium">Sent at</dt><dd>{{ data_get($event->after_snapshot, 'sent_at', __('Not sent')) }}</dd></div>
                <div class="md:col-span-2"><dt class="font-medium">Delivery reference</dt><dd>{{ data_get($event->after_snapshot, 'delivery_reference', __('None')) }}</dd></div>
                <div class="md:col-span-2"><dt class="font-medium">Fingerprint</dt><dd class="break-all font-mono text-xs">{{ $event->fingerprint }}</dd></div>
            </dl>
        </section>
    @endforeach
</div>
