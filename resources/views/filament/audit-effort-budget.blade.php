<div class="space-y-4 text-sm">
    <dl class="grid grid-cols-2 gap-3">
        <div><dt class="font-medium">Version</dt><dd>{{ $budget->version }}</dd></div>
        <div><dt class="font-medium">Planned minutes</dt><dd>{{ number_format($budget->planned_minutes) }}</dd></div>
        <div><dt class="font-medium">Set by</dt><dd>{{ $budget->setter?->name }}</dd></div>
        <div><dt class="font-medium">Set at</dt><dd>{{ $budget->set_at }}</dd></div>
    </dl>
    <div><div class="font-medium">Rationale</div><div class="whitespace-pre-wrap">{{ $budget->rationale }}</div></div>
    <div><div class="font-medium">Immutable allocation snapshot</div><pre class="overflow-auto whitespace-pre-wrap">{{ json_encode($budget->allocation_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></div>
    <div><div class="font-medium">SHA-256 fingerprint</div><div class="break-all">{{ $budget->fingerprint }}</div></div>
</div>
