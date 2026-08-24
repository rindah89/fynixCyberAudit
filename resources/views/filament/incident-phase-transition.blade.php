<div class="space-y-4 text-sm">
    <dl class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div><dt class="font-medium">Decision</dt><dd>{{ $transition->from_phase?->getLabel() ?? 'Created' }} → {{ $transition->to_phase->getLabel() }}</dd></div>
        <div><dt class="font-medium">Actor / time</dt><dd>{{ $transition->actor?->name }} · {{ $transition->transitioned_at?->format('Y-m-d H:i:s') }}</dd></div>
        <div class="sm:col-span-2"><dt class="font-medium">Summary</dt><dd class="whitespace-pre-wrap">{{ $transition->summary }}</dd></div>
        <div class="sm:col-span-2"><dt class="font-medium">SHA-256 decision fingerprint</dt><dd class="break-all font-mono text-xs">{{ $transition->fingerprint }}</dd></div>
    </dl>
    <div>
        <h4 class="font-medium">Authorized retained evidence ({{ $transition->evidence->count() }})</h4>
        <div class="mt-2 space-y-2">
            @forelse ($transition->evidence as $evidence)
                <div class="rounded-lg border p-3">
                    <a class="font-medium underline" href="{{ route('incident-phase-transition-evidence.download', $evidence) }}">{{ $evidence->file_name_snapshot }}</a>
                    <div class="break-all font-mono text-xs">{{ $evidence->sha256 }}</div>
                    <div>{{ $evidence->file_size_snapshot }} bytes · audit #{{ $evidence->audit_id_snapshot }}</div>
                </div>
            @empty
                <p>No authorized retained evidence is attached to this decision.</p>
            @endforelse
        </div>
    </div>
</div>
