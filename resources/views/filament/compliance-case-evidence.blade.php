<div class="space-y-3">
    <div>Version {{ $record->version }} · {{ $record->actor?->name }} · {{ $record->recorded_at?->toDayDateTimeString() }}</div>
    <div class="whitespace-pre-wrap">{{ $record->summary }}</div>
    <details><summary class="font-medium">Retained case and event context</summary><pre class="mt-1 overflow-auto whitespace-pre-wrap text-xs">{{ json_encode(['case' => $record->case_snapshot, 'latest_event' => $record->latest_event_snapshot, 'actor' => $record->actor_snapshot], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre></details>
    @foreach ($record->evidence as $evidence)
        <div><a class="underline" href="{{ route('compliance-case-evidence.download', $evidence) }}">{{ $evidence->file_name_snapshot }}</a> · {{ $evidence->file_size_snapshot }} bytes · <span class="font-mono text-xs">{{ $evidence->sha256 }}</span></div>
    @endforeach
    <div class="break-all font-mono text-xs">{{ $record->fingerprint }}</div>
</div>
