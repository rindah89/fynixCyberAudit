<div class="space-y-4 text-sm" style="color: var(--gray-700)">
    <div><strong>{{ __('Procedure') }}:</strong> {{ $execution->procedure_index }}. {{ $execution->procedure_text }}</div>
    <div><strong>{{ __('Result') }}:</strong> {{ $execution->result->getLabel() }}</div>
    <div><strong>{{ __('Summary') }}:</strong> {{ $execution->summary }}</div>
    <div><strong>{{ __('Findings') }}:</strong> {{ $execution->findings ?: __('None recorded') }}</div>
    <div><strong>{{ __('Source reference') }}:</strong> {{ $execution->source_reference ?: __('None recorded') }}</div>
    <div><strong>{{ __('Executor') }}:</strong> {{ $execution->executor_snapshot['name'] }} ({{ $execution->executor_snapshot['email'] }})</div>
    <div><strong>{{ __('Executed at') }}:</strong> {{ $execution->executed_at->toIso8601String() }}</div>
    <div><strong>{{ __('Plan snapshot') }}:</strong><pre class="mt-1 overflow-auto rounded p-3" style="background: var(--gray-100)">{{ json_encode($execution->plan_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></div>
    <div><strong>{{ __('Case snapshot') }}:</strong><pre class="mt-1 overflow-auto rounded p-3" style="background: var(--gray-100)">{{ json_encode($execution->case_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></div>
    <div><strong>{{ __('Fingerprint') }}:</strong> <code>{{ $execution->fingerprint }}</code></div>
</div>
