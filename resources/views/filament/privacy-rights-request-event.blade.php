<div class="space-y-3 text-sm">
    <div><strong>Version:</strong> {{ $record->version }}</div>
    <div><strong>Status:</strong> {{ $record->to_status->getLabel() }}</div>
    <div><strong>Summary:</strong> {{ $record->summary }}</div>
    <div><strong>Actor:</strong> {{ $record->actor?->name }}</div>
    <div><strong>Recorded:</strong> {{ $record->recorded_at?->toDayDateTimeString() }}</div>
    <div><strong>SHA-256:</strong> {{ $record->fingerprint }}</div>
    <pre class="overflow-auto whitespace-pre-wrap rounded-lg bg-gray-50 p-3">{{ json_encode($record->request_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
</div>
