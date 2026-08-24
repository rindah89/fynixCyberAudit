<div class="space-y-4 text-sm">
    <dl class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div><dt class="font-medium">Type / visibility</dt><dd>{{ $record->entry_type->getLabel() }} · {{ $record->visibility->getLabel() }}</dd></div>
        <div><dt class="font-medium">Occurred / recorded</dt><dd>{{ $record->occurred_at?->format('Y-m-d H:i:s') }} · {{ $record->recorded_at?->format('Y-m-d H:i:s') }}</dd></div>
        <div class="sm:col-span-2"><dt class="font-medium">Summary</dt><dd class="whitespace-pre-wrap">{{ $record->summary }}</dd></div>
        @if ($record->details)<div class="sm:col-span-2"><dt class="font-medium">Details</dt><dd class="whitespace-pre-wrap">{{ $record->details }}</dd></div>@endif
        <div class="sm:col-span-2"><dt class="font-medium">Incident snapshot</dt><dd><pre class="overflow-x-auto whitespace-pre-wrap text-xs">{{ json_encode($record->incident_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre></dd></div>
        <div class="sm:col-span-2"><dt class="font-medium">SHA-256 fingerprint</dt><dd class="break-all font-mono text-xs">{{ $record->fingerprint }}</dd></div>
    </dl>
</div>
