<div class="space-y-4 text-sm">
    <div><strong>{{ $finding->code }} · {{ $finding->severity->getLabel() }} · {{ $finding->title }}</strong></div>
    @foreach (['condition' => 'Condition', 'criteria' => 'Criteria', 'cause' => 'Cause', 'effect' => 'Effect', 'recommendation' => 'Recommendation'] as $field => $label)
        <div><strong>{{ $label }}:</strong><div class="whitespace-pre-wrap">{{ $finding->{$field} ?: 'Not recorded' }}</div></div>
    @endforeach
    <div><strong>Accountable owner:</strong> {{ $finding->accountableOwner?->name }}</div>
    <div><strong>Raised by / at:</strong> {{ $finding->raiser?->name }} · {{ $finding->raised_at }}</div>
    <div class="break-all"><strong>Finding fingerprint:</strong> {{ $finding->fingerprint }}</div>
    <details><summary class="cursor-pointer font-semibold">Immutable source snapshot</summary><pre class="mt-2 overflow-auto whitespace-pre-wrap">{{ json_encode($finding->source_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></details>
    <hr>
    <div class="font-semibold">Management-response history</div>
    @forelse ($finding->responses->sortBy('version') as $response)
        <div><strong>v{{ $response->version }} · {{ $response->position->getLabel() }}</strong> · {{ $response->respondent?->name }} · {{ $response->responded_at }}<br>
            <span class="whitespace-pre-wrap">{{ $response->response }}</span><br>
            <strong>Action / target:</strong> {{ $response->action_plan ?: 'None' }} · {{ $response->target_date?->toDateString() ?: 'None' }}<br>
            <span class="break-all"><strong>Fingerprint:</strong> {{ $response->fingerprint }}</span></div>
    @empty
        <div>Awaiting accountable management response.</div>
    @endforelse
    <hr>
    <div class="font-semibold">Governed remediation and effectiveness follow-up</div>
    @if ($finding->remediation)
        <div><strong>Task:</strong> {{ $finding->remediation->task?->number }} · {{ $finding->remediation->task?->title }} · {{ $finding->remediation->task?->status }}</div>
        <div><strong>Handed off by / at:</strong> {{ $finding->remediation->handoffActor?->name }} · {{ $finding->remediation->handed_off_at }}</div>
        <div class="break-all"><strong>Handoff fingerprint:</strong> {{ $finding->remediation->fingerprint }}</div>
        @forelse ($finding->remediation->followUps->sortBy('version') as $followUp)
            <div><strong>Follow-up v{{ $followUp->version }} · {{ $followUp->outcome->getLabel() }}</strong> · {{ $followUp->reviewer?->name }} · {{ $followUp->reviewed_at }}<br>
                <span class="whitespace-pre-wrap">{{ $followUp->summary }}</span><br>
                <strong>Evidence reference:</strong> {{ $followUp->evidence_reference ?: 'None' }}<br>
                <span class="break-all"><strong>Fingerprint:</strong> {{ $followUp->fingerprint }}</span></div>
        @empty
            <div>Awaiting independent effectiveness follow-up after task completion.</div>
        @endforelse
    @else
        <div>No governed remediation handoff recorded.</div>
    @endif
</div>
