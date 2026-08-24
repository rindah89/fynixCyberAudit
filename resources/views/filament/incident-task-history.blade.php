<div class="space-y-4 text-sm">
    @foreach ($task->events as $event)
        <section class="rounded-lg border border-gray-200 bg-white p-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <strong>Version {{ $event->version }} · {{ __($event->event_type === 'seeded' ? 'Seeded' : 'Updated') }}</strong>
                <span>{{ $event->recorded_at?->format('Y-m-d H:i:s') }} · {{ $event->actor?->name }}</span>
            </div>
            <p class="mt-2">{{ $event->summary }}</p>
            <dl class="mt-3 grid gap-2 md:grid-cols-2">
                <div><dt class="font-medium">Status</dt><dd>{{ $event->from_status?->getLabel() ?? __('Created') }} → {{ $event->to_status->getLabel() }}</dd></div>
                <div><dt class="font-medium">Assignee</dt><dd>{{ data_get($event->after_snapshot, 'assignee.name', __('Unassigned')) }}</dd></div>
                <div><dt class="font-medium">Due date</dt><dd>{{ data_get($event->after_snapshot, 'due_date', __('None')) }}</dd></div>
                <div><dt class="font-medium">Fingerprint</dt><dd class="break-all font-mono text-xs">{{ $event->fingerprint }}</dd></div>
            </dl>
        </section>
    @endforeach
</div>
