<div class="space-y-4 text-sm" style="color: var(--gray-700);">
    <div class="grid gap-3 sm:grid-cols-2">
        <div><span class="font-semibold">Version:</span> {{ $version->version }}</div>
        <div><span class="font-semibold">Change:</span> {{ $version->change_type->getLabel() }}</div>
        <div><span class="font-semibold">Status:</span> {{ $version->status->getLabel() }}</div>
        <div><span class="font-semibold">Effective:</span> {{ $version->effective_at?->toDateString() }}</div>
        <div><span class="font-semibold">Expires:</span> {{ $version->expires_at?->toDateString() ?? 'No expiry' }}</div>
        <div><span class="font-semibold">Published:</span> {{ $version->published_at?->toDateTimeString() }}</div>
    </div>
    <div><div class="font-semibold">Title</div><div>{{ $version->title }}</div></div>
    <div><div class="font-semibold">Requirement text</div><div class="whitespace-pre-wrap">{{ $version->requirement_text }}</div></div>
    @foreach (['source_snapshot' => 'Source snapshot', 'policy_snapshots' => 'Policy snapshots', 'control_snapshots' => 'Control snapshots'] as $field => $label)
        <div><div class="font-semibold">{{ $label }}</div><pre class="overflow-auto rounded p-3 text-xs" style="background: var(--gray-100);">{{ json_encode($version->{$field}, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></div>
    @endforeach
    <div><span class="font-semibold">Mapped policy IDs:</span> {{ implode(', ', $version->policy_ids) ?: 'None' }}</div>
    <div><span class="font-semibold">Mapped control IDs:</span> {{ implode(', ', $version->control_ids) ?: 'None' }}</div>
    <div class="break-all"><span class="font-semibold">Fingerprint:</span> {{ $version->content_fingerprint }}</div>
</div>
