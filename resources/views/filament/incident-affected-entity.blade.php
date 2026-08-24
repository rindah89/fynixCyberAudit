<div class="space-y-4 text-sm">
    <dl class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div><dt class="font-medium">Type / source ID</dt><dd>{{ $record->entity_type->getLabel() }} #{{ $record->entity_id_snapshot }}</dd></div>
        <div><dt class="font-medium">Linked by / time</dt><dd>{{ $record->linkedBy?->name }} · {{ $record->linked_at?->format('Y-m-d H:i:s') }}</dd></div>
        <div class="sm:col-span-2"><dt class="font-medium">Impact summary</dt><dd class="whitespace-pre-wrap">{{ $record->impact_summary }}</dd></div>
        @if ($record->control_failure_note)<div class="sm:col-span-2"><dt class="font-medium">Control failure note</dt><dd class="whitespace-pre-wrap">{{ $record->control_failure_note }}</dd></div>@endif
        <div class="sm:col-span-2"><dt class="font-medium">Immutable source snapshot</dt><dd><pre class="overflow-x-auto whitespace-pre-wrap text-xs">{{ json_encode($record->entity_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre></dd></div>
        <div class="sm:col-span-2"><dt class="font-medium">SHA-256 fingerprint</dt><dd class="break-all font-mono text-xs">{{ $record->fingerprint }}</dd></div>
    </dl>
</div>
