<div class="space-y-4 text-sm">
    <dl class="grid grid-cols-2 gap-3">
        <div><dt class="font-medium">Type</dt><dd>{{ $entry->entry_type->getLabel() }}</dd></div>
        <div><dt class="font-medium">Work date</dt><dd>{{ $entry->work_date->toDateString() }}</dd></div>
        <div><dt class="font-medium">Minutes</dt><dd>{{ number_format($entry->minutes) }}</dd></div>
        <div><dt class="font-medium">Entered by / at</dt><dd>{{ $entry->entrant?->name }} · {{ $entry->entered_at }}</dd></div>
    </dl>
    <div><div class="font-medium">Activity</div><div>{{ $entry->activity }}</div></div>
    <div><div class="font-medium">Notes</div><div class="whitespace-pre-wrap">{{ $entry->notes ?: 'None' }}</div></div>
    <div><div class="font-medium">Source reference</div><div>{{ $entry->source_reference ?: 'None' }}</div></div>
    <div><div class="font-medium">Budget snapshot</div><pre class="overflow-auto whitespace-pre-wrap">{{ json_encode($entry->budget_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></div>
    <div><div class="font-medium">Procedure snapshot</div><pre class="overflow-auto whitespace-pre-wrap">{{ json_encode($entry->procedure_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></div>
    <div><div class="font-medium">SHA-256 fingerprint</div><div class="break-all">{{ $entry->fingerprint }}</div></div>
</div>
