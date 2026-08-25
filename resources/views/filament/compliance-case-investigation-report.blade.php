<div class="space-y-4 text-sm" style="color: var(--gray-700)">
    <div><strong>{{ __('Version') }}:</strong> {{ $report->version }}</div>
    <div><strong>{{ __('Outcome') }}:</strong> {{ $report->outcome->getLabel() }}</div>
    <div><strong>{{ __('Executive summary') }}:</strong> {{ $report->executive_summary }}</div>
    <div><strong>{{ __('Analysis') }}:</strong> {{ $report->analysis }}</div>
    <div><strong>{{ __('Findings') }}:</strong> {{ $report->findings }}</div>
    <div><strong>{{ __('Recommendations') }}:</strong> {{ $report->recommendations }}</div>
    <div><strong>{{ __('Author') }}:</strong> {{ $report->author_snapshot['name'] }} ({{ $report->author_snapshot['email'] }})</div>
    <div><strong>{{ __('Authored at') }}:</strong> {{ $report->authored_at->toIso8601String() }}</div>
    <div><strong>{{ __('Report snapshot') }}:</strong><pre class="mt-1 overflow-auto rounded p-3" style="background: var(--gray-100)">{{ json_encode($report->report_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></div>
    <div><strong>{{ __('Fingerprint') }}:</strong> <code>{{ $report->fingerprint }}</code></div>
    @if ($report->review)
        <div><strong>{{ __('Decision') }}:</strong> {{ $report->review->decision->getLabel() }}</div>
        <div><strong>{{ __('Review summary') }}:</strong> {{ $report->review->summary }}</div>
        <div><strong>{{ __('Reviewer') }}:</strong> {{ $report->review->reviewer_snapshot['name'] }} ({{ $report->review->reviewer_snapshot['email'] }})</div>
        <div><strong>{{ __('Reviewed at') }}:</strong> {{ $report->review->reviewed_at->toIso8601String() }}</div>
        <div><strong>{{ __('Retained report review snapshot') }}:</strong><pre class="mt-1 overflow-auto rounded p-3" style="background: var(--gray-100)">{{ json_encode($report->review->report_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></div>
        <div><strong>{{ __('Review fingerprint') }}:</strong> <code>{{ $report->review->fingerprint }}</code></div>
    @endif
</div>
