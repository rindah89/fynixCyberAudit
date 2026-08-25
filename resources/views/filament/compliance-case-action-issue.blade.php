<div class="space-y-4 text-sm" style="color: var(--gray-700);">
    <div><span class="font-semibold">Issue:</span> {{ $record->title }}</div>
    <div><span class="font-semibold">Owner:</span> {{ $record->owner?->name }} · <span class="font-semibold">Opened by:</span> {{ $record->opener?->name }}</div>
    <div><span class="font-semibold">Status:</span> {{ $record->lifecycle?->status?->getLabel() }} · <span class="font-semibold">Severity:</span> {{ $record->severity }}</div>
    <div><span class="font-semibold">Description:</span> {{ $record->description }}</div>
    <div><span class="font-semibold">Source snapshot</span><pre class="mt-1 overflow-auto whitespace-pre-wrap rounded-lg p-3" style="background: var(--gray-100);">{{ json_encode($record->source_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></div>
    <div><span class="font-semibold">Lifecycle transitions</span><pre class="mt-1 overflow-auto whitespace-pre-wrap rounded-lg p-3" style="background: var(--gray-100);">{{ json_encode($record->lifecycle?->transitions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></div>
    <div><span class="font-semibold">SHA-256:</span> <span class="break-all">{{ $record->fingerprint }}</span></div>
</div>
