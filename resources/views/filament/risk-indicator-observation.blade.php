<div class="space-y-4 text-sm text-gray-700">
    <dl class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div><dt class="font-medium text-gray-950">Indicator</dt><dd>{{ $observation->indicator->code }} — {{ $observation->indicator->name }}</dd></div>
        <div><dt class="font-medium text-gray-950">Observed value</dt><dd>{{ $observation->observed_value }} {{ $observation->unit_snapshot }}</dd></div>
        <div><dt class="font-medium text-gray-950">Derived status</dt><dd>{{ $observation->status->getLabel() }}</dd></div>
        <div><dt class="font-medium text-gray-950">Adverse direction</dt><dd>{{ $observation->direction_snapshot->getLabel() }}</dd></div>
        <div><dt class="font-medium text-gray-950">Warning threshold</dt><dd>{{ $observation->warning_threshold_snapshot }}</dd></div>
        <div><dt class="font-medium text-gray-950">Critical threshold</dt><dd>{{ $observation->critical_threshold_snapshot }}</dd></div>
        <div><dt class="font-medium text-gray-950">Observed by</dt><dd>{{ $observation->observer->name }}</dd></div>
        <div><dt class="font-medium text-gray-950">Observed at</dt><dd>{{ $observation->observed_at->toDayDateTimeString() }}</dd></div>
        <div class="sm:col-span-2"><dt class="font-medium text-gray-950">Decision reason</dt><dd class="whitespace-pre-wrap">{{ $observation->reason }}</dd></div>
        <div class="sm:col-span-2"><dt class="font-medium text-gray-950">Source reference</dt><dd>{{ $observation->source_reference ?: 'Not supplied' }}</dd></div>
        <div class="sm:col-span-2"><dt class="font-medium text-gray-950">Notes</dt><dd class="whitespace-pre-wrap">{{ $observation->notes ?: 'Not supplied' }}</dd></div>
    </dl>
</div>
