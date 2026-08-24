<div class="space-y-4 text-sm">
    <div><strong>Procedure:</strong> {{ $procedure->code }} v{{ $procedure->version }} — {{ $procedure->title }}</div>
    <div><strong>Objective:</strong><div class="whitespace-pre-wrap">{{ $procedure->objective }}</div></div>
    <div><strong>Steps:</strong><div class="whitespace-pre-wrap">{{ $procedure->steps }}</div></div>
    <div><strong>Method / population / planned sample:</strong> {{ $procedure->method->getLabel() }} · {{ $procedure->population_description ?: 'Not specified' }} · {{ $procedure->planned_sample_size ?? 'Not specified' }}</div>
    <div><strong>Assigned / due:</strong> {{ $procedure->assignee?->name }} · {{ $procedure->due_at?->toDateString() ?? 'No due date' }}</div>
    @if ($procedure->execution)
        <hr>
        <div><strong>Outcome:</strong> {{ $procedure->execution->outcome->getLabel() }}</div>
        <div><strong>Result:</strong><div class="whitespace-pre-wrap">{{ $procedure->execution->result }}</div></div>
        <div><strong>Exceptions:</strong><div class="whitespace-pre-wrap">{{ $procedure->execution->exceptions ?: 'None recorded' }}</div></div>
        <div><strong>Sample tested / evidence reference:</strong> {{ $procedure->execution->sample_tested ?? 'Not specified' }} · {{ $procedure->execution->evidence_reference ?: 'None' }}</div>
        <div><strong>Executed by / at:</strong> {{ $procedure->execution->executor?->name }} · {{ $procedure->execution->executed_at?->toIso8601String() }}</div>
        <div class="break-all"><strong>Fingerprint:</strong> {{ $procedure->execution->fingerprint }}</div>
        @php
            $actor = auth()->user();
            $authorizedEvidence = $procedure->execution->evidence->filter(fn ($record) => $actor && $record->attachment && app(\App\Access\FileAccess::class)->canDownloadFileAttachment($actor, $record->attachment));
            $authorizedEvidenceIds = $authorizedEvidence->pluck('file_attachment_id');
            $reviewSnapshot = $procedure->execution->review?->execution_snapshot;
            if (is_array($reviewSnapshot)) {
                $reviewSnapshot['evidence_manifest'] = collect($reviewSnapshot['evidence_manifest'] ?? [])->whereIn('file_attachment_id', $authorizedEvidenceIds)->values()->all();
            }
        @endphp
        @foreach ($authorizedEvidence as $record)
            <div>
                <strong>Governed evidence:</strong>
                <a class="font-medium text-primary-600 hover:underline" href="{{ route('audit-procedure-execution-evidence.download', $record) }}">{{ $record->file_name_snapshot }}</a>
                · {{ number_format($record->file_size_snapshot) }} bytes
                · <span class="break-all">SHA-256 {{ $record->sha256 }}</span>
            </div>
        @endforeach
        <details><summary class="cursor-pointer font-semibold">Immutable execution snapshot</summary><pre class="mt-2 overflow-auto whitespace-pre-wrap">{{ json_encode($procedure->execution->procedure_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></details>
        @if ($procedure->execution->review)
            <hr>
            <div><strong>Supervisory decision:</strong> {{ $procedure->execution->review->decision->getLabel() }}</div>
            <div><strong>Review summary:</strong><div class="whitespace-pre-wrap">{{ $procedure->execution->review->review_summary }}</div></div>
            <div><strong>Reviewed by / at:</strong> {{ $procedure->execution->review->reviewer?->name }} · {{ $procedure->execution->review->reviewed_at?->toIso8601String() }}</div>
            <div class="break-all"><strong>Review fingerprint:</strong> {{ $procedure->execution->review->fingerprint }}</div>
            <details><summary class="cursor-pointer font-semibold">Immutable reviewed-execution snapshot</summary><pre class="mt-2 overflow-auto whitespace-pre-wrap">{{ json_encode($reviewSnapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></details>
        @else
            <div><strong>Supervisory review:</strong> Pending</div>
        @endif
    @else
        <div><strong>Execution:</strong> Pending</div>
    @endif
</div>
