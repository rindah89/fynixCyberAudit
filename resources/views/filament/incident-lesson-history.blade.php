<div class="space-y-4 text-sm">
    @foreach ($lesson->events as $event)
        <section class="rounded-lg border border-gray-200 bg-white p-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <strong>Version {{ $event->version }} · {{ __($event->event_type === 'registered' ? 'Registered' : 'Progress') }}</strong>
                <span>{{ $event->recorded_at?->format('Y-m-d H:i:s') }} · {{ $event->actor?->name }}</span>
            </div>
            <p class="mt-2">{{ $event->rationale }}</p>
            <dl class="mt-3 grid gap-2 md:grid-cols-2">
                <div><dt class="font-medium">Area</dt><dd>{{ data_get($event->after_snapshot, 'area') }}</dd></div>
                <div><dt class="font-medium">Status</dt><dd>{{ data_get($event->after_snapshot, 'status') }}</dd></div>
                <div class="md:col-span-2"><dt class="font-medium">Observation</dt><dd>{{ data_get($event->after_snapshot, 'observation') }}</dd></div>
                <div class="md:col-span-2"><dt class="font-medium">Recommendation</dt><dd>{{ data_get($event->after_snapshot, 'recommendation') }}</dd></div>
                <div><dt class="font-medium">Owner</dt><dd>{{ data_get($event->after_snapshot, 'owner.name') }}</dd></div>
                <div><dt class="font-medium">Target date</dt><dd>{{ data_get($event->after_snapshot, 'target_date', __('None')) }}</dd></div>
                <div class="md:col-span-2"><dt class="font-medium">Fingerprint</dt><dd class="break-all font-mono text-xs">{{ $event->fingerprint }}</dd></div>
            </dl>
        </section>
    @endforeach
</div>
