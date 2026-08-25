<div class="space-y-4 text-sm" style="color: var(--gray-700)">
    <div><strong>{{ __('Subject') }}:</strong> {{ $interview->subjectUser?->name ?? $interview->subject_reference }}</div>
    <div><strong>{{ __('Interviewer') }}:</strong> {{ $interview->interviewer?->name }}</div>
    @foreach ($interview->events as $event)
        <section class="rounded-lg p-4" style="background: var(--gray-100)">
            <div><strong>{{ __('Version') }} {{ $event->version }} · {{ str($event->event_type)->headline() }}</strong></div>
            <div>{{ __('Recorded by') }} {{ $event->actor?->name }} · {{ $event->recorded_at?->toDayDateTimeString() }}</div>
            <div class="mt-2">{{ $event->rationale }}</div>
            <div class="mt-2 break-all"><strong>{{ __('Fingerprint') }}:</strong> {{ $event->fingerprint }}</div>
            <details class="mt-2"><summary>{{ __('Retained snapshots') }}</summary><pre class="mt-2 whitespace-pre-wrap">{{ json_encode(['before' => $event->before_snapshot, 'after' => $event->after_snapshot], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></details>
        </section>
    @endforeach
</div>
