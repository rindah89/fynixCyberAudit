<div class="space-y-4 text-sm">
    <dl class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div><dt class="font-medium">Status</dt><dd>{{ $revision->status->getLabel() }}</dd></div>
        <div><dt class="font-medium">Effective date</dt><dd>{{ $revision->proposed_effective_date->toDateString() }}</dd></div>
        <div><dt class="font-medium">Submitted by</dt><dd>{{ $revision->submitter?->name }}</dd></div>
        <div><dt class="font-medium">Submitted at</dt><dd>{{ $revision->submitted_at }}</dd></div>
        <div class="sm:col-span-2"><dt class="font-medium">Change summary</dt><dd class="whitespace-pre-wrap">{{ $revision->change_summary }}</dd></div>
        <div class="sm:col-span-2"><dt class="font-medium">Revision fingerprint</dt><dd class="break-all font-mono">{{ $revision->fingerprint }}</dd></div>
        @if ($revision->review)
            <div><dt class="font-medium">Decision</dt><dd>{{ $revision->review->decision->getLabel() }}</dd></div>
            <div><dt class="font-medium">Reviewed by</dt><dd>{{ $revision->review->reviewer?->name }}</dd></div>
            <div class="sm:col-span-2"><dt class="font-medium">Review summary</dt><dd class="whitespace-pre-wrap">{{ $revision->review->review_summary }}</dd></div>
            <div class="sm:col-span-2"><dt class="font-medium">Review fingerprint</dt><dd class="break-all font-mono">{{ $revision->review->fingerprint }}</dd></div>
        @endif
    </dl>
    <div>
        <h4 class="font-medium">Immutable policy and mapping snapshot</h4>
        <pre class="mt-2 max-h-96 overflow-auto whitespace-pre-wrap rounded-lg border p-3 text-xs">{{ json_encode($revision->policy_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
    </div>
</div>
