<div class="space-y-4 text-sm">
    <h3 class="font-semibold">{{ $title }}</h3>
    @if (isset($record->observed_value))
        <dl class="grid grid-cols-2 gap-3">
            <div><dt class="font-medium">Observed value</dt><dd>{{ $record->observed_value }}</dd></div>
            <div><dt class="font-medium">Status</dt><dd>{{ $record->status?->getLabel() }}</dd></div>
            <div><dt class="font-medium">Reason</dt><dd>{{ $record->reason }}</dd></div>
            <div><dt class="font-medium">Source reference</dt><dd>{{ $record->source_reference ?: 'Not supplied' }}</dd></div>
            <div class="col-span-2"><dt class="font-medium">Notes</dt><dd class="whitespace-pre-wrap">{{ $record->notes ?: 'Not supplied' }}</dd></div>
        </dl>
    @else
        <dl class="grid grid-cols-2 gap-3">
            <div><dt class="font-medium">Baseline</dt><dd>{{ $record->baseline_value }} {{ $record->unit }}</dd></div>
            <div><dt class="font-medium">Target</dt><dd>{{ $record->target_value }} {{ $record->unit }}</dd></div>
            <div class="col-span-2"><dt class="font-medium">Measurement method</dt><dd class="whitespace-pre-wrap">{{ $record->measurement_method }}</dd></div>
        </dl>
    @endif
    <pre class="overflow-auto whitespace-pre-wrap">{{ json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
    <div class="break-all"><span class="font-medium">SHA-256:</span> {{ $record->fingerprint }}</div>
</div>
