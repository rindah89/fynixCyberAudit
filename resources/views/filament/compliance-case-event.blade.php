<div class="space-y-4 text-sm text-stone-700">
    <div><span class="font-semibold text-stone-950">Event:</span> {{ $record->event_type }} · version {{ $record->version }}</div>
    <div><span class="font-semibold text-stone-950">Actor:</span> {{ $record->actor?->name }} · {{ $record->recorded_at?->format('Y-m-d H:i:s') }}</div>
    <div><span class="font-semibold text-stone-950">Rationale:</span> {{ $record->summary }}</div>
    <div><span class="font-semibold text-stone-950">Before snapshot</span><pre class="mt-1 overflow-auto whitespace-pre-wrap rounded-lg bg-stone-100 p-3">{{ json_encode($record->before_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></div>
    <div><span class="font-semibold text-stone-950">After snapshot</span><pre class="mt-1 overflow-auto whitespace-pre-wrap rounded-lg bg-stone-100 p-3">{{ json_encode($record->after_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></div>
    <div><span class="font-semibold text-stone-950">SHA-256:</span> <span class="break-all">{{ $record->fingerprint }}</span></div>
</div>
