<div class="space-y-4 text-sm" style="color: var(--gray-700);">
    <div class="grid gap-3 sm:grid-cols-2">
        <div><span class="font-semibold">Assessment:</span> {{ $assessment->assessment_version }}</div>
        <div><span class="font-semibold">Applicability:</span> {{ $assessment->applicability->getLabel() }}</div>
        <div><span class="font-semibold">Impact:</span> {{ $assessment->impact->getLabel() }}</div>
        <div><span class="font-semibold">Assessed:</span> {{ $assessment->assessed_at?->toDateTimeString() }}</div>
        <div><span class="font-semibold">Action owner:</span> {{ $assessment->actionOwner?->name ?? 'None' }}</div>
        <div><span class="font-semibold">Action due:</span> {{ $assessment->action_due_at?->toDateString() ?? 'None' }}</div>
    </div>
    <div><div class="font-semibold">Summary</div><div class="whitespace-pre-wrap">{{ $assessment->summary }}</div></div>
    <div><div class="font-semibold">Rationale</div><div class="whitespace-pre-wrap">{{ $assessment->rationale }}</div></div>
    @foreach (['requirement_snapshot' => 'Requirement and source snapshot', 'policy_snapshots' => 'Policy snapshots', 'control_snapshots' => 'Control snapshots'] as $field => $label)
        <div><div class="font-semibold">{{ $label }}</div><pre class="overflow-auto rounded p-3 text-xs" style="background: var(--gray-100);">{{ json_encode($assessment->{$field}, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></div>
    @endforeach
    <div class="break-all"><span class="font-semibold">Fingerprint:</span> {{ $assessment->content_fingerprint }}</div>
</div>
