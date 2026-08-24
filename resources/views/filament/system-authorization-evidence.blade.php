<div class="space-y-4 text-sm">
    <dl class="grid grid-cols-2 gap-3"><div><dt class="text-gray-500">Decision</dt><dd>{{ $record->decision->getLabel() }}</dd></div><div><dt class="text-gray-500">Valid until</dt><dd>{{ $record->valid_until?->toDateString() ?? 'Not applicable' }}</dd></div></dl>
    <div><div class="text-gray-500">Rationale</div><div class="whitespace-pre-wrap">{{ $record->rationale }}</div></div>
    <div><div class="text-gray-500">Conditions</div><ul class="list-disc pl-5">@forelse($record->conditions as $condition)<li>{{ $condition }}</li>@empty<li>None</li>@endforelse</ul></div>
    <div><div class="text-gray-500">Exact package snapshot</div><pre class="overflow-auto whitespace-pre-wrap">{{ json_encode($record->package_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></div>
    <div class="break-all"><span class="text-gray-500">SHA-256:</span> {{ $record->fingerprint }}</div>
</div>
