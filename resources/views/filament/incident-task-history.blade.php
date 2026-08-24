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
            @if ($event->evidence->isNotEmpty())
                <div class="mt-3 border-t border-gray-200 pt-3">
                    <strong>{{ __('Governed evidence') }}</strong>
                    <ul class="mt-2 space-y-1">
                        @foreach ($event->evidence as $evidence)
                            <li><a class="underline" href="{{ route('incident-task-event-evidence.download', $evidence) }}">{{ $evidence->file_name_snapshot }}</a> · {{ $evidence->file_size_snapshot }} bytes · <span class="font-mono text-xs">{{ $evidence->sha256 }}</span></li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </section>
    @endforeach
</div>
