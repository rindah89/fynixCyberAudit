<div class="space-y-4 text-sm">
    <dl class="grid grid-cols-2 gap-3">
        <div><dt class="font-medium">Decision</dt><dd>{{ $decision->decision->getLabel() }}</dd></div>
        <div><dt class="font-medium">Decided at</dt><dd>{{ $decision->decided_at }}</dd></div>
        <div class="col-span-2"><dt class="font-medium">Rationale</dt><dd class="whitespace-pre-wrap">{{ $decision->rationale }}</dd></div>
    </dl>
    <pre class="overflow-auto whitespace-pre-wrap">{{ json_encode($decision->disclosure_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
    <div class="break-all"><span class="font-medium">SHA-256:</span> {{ $decision->fingerprint }}</div>
</div>
