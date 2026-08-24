@php($review = $submission->review)
<div class="space-y-5 text-sm" style="color: var(--gray-700);">
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <div><span class="font-semibold">Version</span><div>{{ $submission->version }}</div></div>
        <div><span class="font-semibold">Opinion</span><div>{{ $submission->opinion->getLabel() }}</div></div>
        <div><span class="font-semibold">Submitted</span><div>{{ $submission->submitter?->name }} · {{ $submission->submitted_at }}</div></div>
    </div>
    <div><span class="font-semibold">Executive summary</span><div class="whitespace-pre-wrap">{{ $submission->executive_summary }}</div></div>
    <div><span class="font-semibold">Scope limitations</span><div class="whitespace-pre-wrap">{{ $submission->scope_limitations ?: 'None reported.' }}</div></div>
    <div><span class="font-semibold">Significant matters</span><div class="whitespace-pre-wrap">{{ $submission->significant_matters }}</div></div>
    <div><span class="font-semibold">Recommendations</span><div class="whitespace-pre-wrap">{{ $submission->recommendations_summary }}</div></div>
    <div><span class="font-semibold">Submission fingerprint</span><div class="break-all font-mono text-xs">{{ $submission->fingerprint }}</div></div>
    @foreach (['audit_snapshot' => 'Audit snapshot', 'engagement_baseline_snapshot' => 'Engagement baseline snapshot', 'audit_item_snapshots' => 'Audit item snapshots', 'data_request_snapshots' => 'Data request snapshots'] as $field => $label)
        <div><span class="font-semibold">{{ $label }}</span><pre class="mt-1 overflow-x-auto whitespace-pre-wrap rounded p-3 text-xs" style="background: var(--gray-100);">{{ json_encode($submission->{$field}, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></div>
    @endforeach
    @if ($review)
        <div class="border-t pt-4" style="border-color: var(--gray-200);"><span class="font-semibold">Independent review</span><div>{{ $review->decision->getLabel() }} · {{ $review->reviewer?->name }} · {{ $review->reviewed_at }}</div></div>
        <div class="whitespace-pre-wrap">{{ $review->review_summary }}</div>
        <div><span class="font-semibold">Review fingerprint</span><div class="break-all font-mono text-xs">{{ $review->fingerprint }}</div></div>
        @if ($review->report_sha256)
            <div><span class="font-semibold">Retained final report</span><div>{{ number_format($review->report_size) }} bytes · <span class="break-all font-mono text-xs">{{ $review->report_sha256 }}</span></div></div>
            <a class="underline" style="color: var(--primary-700);" href="{{ route('audit-closeout-reviews.report', $review) }}">Download authorized final report</a>
        @endif
    @else
        <div class="border-t pt-4" style="border-color: var(--gray-200); color: var(--gray-500);">Awaiting independent review.</div>
    @endif
</div>
