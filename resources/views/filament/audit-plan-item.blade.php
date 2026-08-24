<div class="space-y-4 text-sm" style="color: var(--gray-700);">
    <div class="grid gap-3 sm:grid-cols-3">
        <div><span class="font-semibold">Priority rank:</span> {{ $item->priority_rank }}</div>
        <div><span class="font-semibold">Status:</span> {{ $item->status->getLabel() }}</div>
        <div><span class="font-semibold">Entity:</span> {{ $item->auditableEntity?->code }}</div>
        <div><span class="font-semibold">Planned start:</span> {{ $item->planned_start_at?->toDateString() }}</div>
        <div><span class="font-semibold">Planned end:</span> {{ $item->planned_end_at?->toDateString() }}</div>
        <div><span class="font-semibold">Linked audit:</span> {{ $item->audit?->title ?? 'Not scheduled' }}</div>
    </div>
    <div><div class="font-semibold">Planning rationale</div><div class="whitespace-pre-wrap">{{ $item->rationale }}</div></div>
    <div><div class="font-semibold">Entity and assessment snapshot</div><pre class="overflow-auto rounded p-3 text-xs" style="background: var(--gray-100);">{{ json_encode($item->entity_assessment_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></div>
</div>
