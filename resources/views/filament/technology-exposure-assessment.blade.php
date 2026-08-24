<div class="space-y-4 text-sm text-gray-700">
    <dl class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div><dt class="font-medium text-gray-950">Version</dt><dd>{{ $assessment->version }}</dd></div>
        <div><dt class="font-medium text-gray-950">Type</dt><dd>{{ $assessment->exposure_type->getLabel() }}</dd></div>
        <div class="sm:col-span-2"><dt class="font-medium text-gray-950">Title</dt><dd>{{ $assessment->title }}</dd></div>
        <div><dt class="font-medium text-gray-950">Asset</dt><dd>{{ data_get($assessment->asset_snapshot, 'asset_tag') }} — {{ data_get($assessment->asset_snapshot, 'name') }}</dd></div>
        <div><dt class="font-medium text-gray-950">State</dt><dd>{{ $assessment->state->getLabel() }}</dd></div>
        <div><dt class="font-medium text-gray-950">Inherent score</dt><dd>{{ $assessment->inherent_likelihood }} × {{ $assessment->inherent_impact }} = {{ $assessment->inherent_score }}</dd></div>
        <div><dt class="font-medium text-gray-950">Residual score / appetite</dt><dd>{{ $assessment->residual_likelihood }} × {{ $assessment->residual_impact }} = {{ $assessment->residual_score }} / {{ $assessment->appetite_threshold_snapshot }}</dd></div>
        <div class="sm:col-span-2"><dt class="font-medium text-gray-950">Threat scenario</dt><dd class="whitespace-pre-wrap">{{ $assessment->threat_scenario }}</dd></div>
        <div><dt class="font-medium text-gray-950">Vulnerability reference</dt><dd>{{ $assessment->vulnerability_reference ?: 'Not supplied' }}</dd></div>
        <div><dt class="font-medium text-gray-950">Source reference</dt><dd>{{ $assessment->source_reference ?: 'Not supplied' }}</dd></div>
        <div class="sm:col-span-2"><dt class="font-medium text-gray-950">Vulnerability description</dt><dd class="whitespace-pre-wrap">{{ $assessment->vulnerability_description }}</dd></div>
        <div class="sm:col-span-2"><dt class="font-medium text-gray-950">Recommended response</dt><dd class="whitespace-pre-wrap">{{ $assessment->recommended_response }}</dd></div>
        <div><dt class="font-medium text-gray-950">Review due</dt><dd>{{ $assessment->review_due_at->toDateString() }} ({{ str_replace('_', ' ', $assessment->schedule_status) }})</dd></div>
        <div><dt class="font-medium text-gray-950">Assessed by / at</dt><dd>{{ $assessment->assessor->name }} · {{ $assessment->assessed_at->toDayDateTimeString() }}</dd></div>
    </dl>
    <section>
        <h3 class="font-medium text-gray-950">Governance context snapshot</h3>
        <p>{{ data_get($assessment->governance_snapshot, 'risk.code') }} — {{ data_get($assessment->governance_snapshot, 'risk.name') }}; appetite {{ data_get($assessment->governance_snapshot, 'profile.appetite_threshold') }}</p>
        <ul class="mt-2 list-disc space-y-1 pl-5">
            @foreach (data_get($assessment->governance_snapshot, 'implementations', []) as $implementation)
                <li>{{ data_get($implementation, 'code') }} — {{ data_get($implementation, 'title') }}
                    <ul class="list-disc pl-5">
                        @foreach (data_get($implementation, 'controls', []) as $control)
                            <li>{{ data_get($control, 'code') }} — {{ data_get($control, 'title') }} ({{ data_get($control, 'applicability') }})</li>
                        @endforeach
                    </ul>
                </li>
            @endforeach
        </ul>
        <p class="mt-2 break-all text-xs text-gray-500">Fingerprint: {{ $assessment->governance_fingerprint }}</p>
        <details class="mt-3">
            <summary class="cursor-pointer font-medium text-gray-950">Complete immutable governance snapshot</summary>
            <pre class="mt-2 max-h-96 overflow-auto whitespace-pre-wrap rounded bg-gray-100 p-3 text-xs text-gray-800">{{ json_encode($assessment->governance_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) }}</pre>
        </details>
    </section>
</div>
