<div class="space-y-4 text-sm" style="color: var(--gray-700);">
    <div class="grid gap-3 sm:grid-cols-3">
        <div><span class="font-semibold">Version:</span> {{ $assessment->version }}</div>
        <div><span class="font-semibold">Inherent:</span> {{ $assessment->inherent_likelihood }} × {{ $assessment->inherent_impact }} = {{ $assessment->inherent_score }}</div>
        <div><span class="font-semibold">Residual:</span> {{ $assessment->residual_likelihood }} × {{ $assessment->residual_impact }} = {{ $assessment->residual_score }}</div>
        <div><span class="font-semibold">Priority:</span> {{ str($assessment->priority_band)->title() }}</div>
        <div><span class="font-semibold">Next assessment:</span> {{ $assessment->next_assessment_at?->toDateString() }}</div>
        <div><span class="font-semibold">Assessed:</span> {{ $assessment->assessed_at?->toDateTimeString() }}</div>
    </div>
    <div><div class="font-semibold">Rationale</div><div class="whitespace-pre-wrap">{{ $assessment->rationale }}</div></div>
    @foreach (['entity_snapshot' => 'Entity snapshot', 'risk_snapshots' => 'Risk snapshots', 'control_snapshots' => 'Control snapshots'] as $field => $label)
        <div><div class="font-semibold">{{ $label }}</div><pre class="overflow-auto rounded p-3 text-xs" style="background: var(--gray-100);">{{ json_encode($assessment->{$field}, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></div>
    @endforeach
    <div class="break-all"><span class="font-semibold">Fingerprint:</span> {{ $assessment->governance_fingerprint }}</div>
</div>
