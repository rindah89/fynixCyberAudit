<dl class="grid gap-4 text-sm sm:grid-cols-2">
    <div><dt class="font-medium text-gray-900">Occurred</dt><dd class="text-gray-600">{{ $event->occurred_at->toDateString() }}</dd></div>
    <div><dt class="font-medium text-gray-900">Detected</dt><dd class="text-gray-600">{{ $event->detected_at->toDateString() }}</dd></div>
    <div><dt class="font-medium text-gray-900">Category</dt><dd class="text-gray-600">{{ $event->category->getLabel() }}</dd></div>
    <div><dt class="font-medium text-gray-900">Business service</dt><dd class="text-gray-600">{{ data_get($event->business_service_snapshot, 'code') }} — {{ data_get($event->business_service_snapshot, 'name') }}</dd></div>
    <div><dt class="font-medium text-gray-900">Service status</dt><dd class="text-gray-600">{{ data_get($event->business_service_snapshot, 'status') }}</dd></div>
    <div><dt class="font-medium text-gray-900">Service criticality</dt><dd class="text-gray-600">{{ data_get($event->business_service_snapshot, 'criticality') }}</dd></div>
    <div><dt class="font-medium text-gray-900">Service owner ID</dt><dd class="text-gray-600">{{ data_get($event->business_service_snapshot, 'owner_id') }}</dd></div>
    <div><dt class="font-medium text-gray-900">Currency</dt><dd class="text-gray-600">{{ $event->currency }}</dd></div>
    <div><dt class="font-medium text-gray-900">Gross loss</dt><dd class="text-gray-600">{{ $event->currency }} {{ $event->gross_loss }}</dd></div>
    <div><dt class="font-medium text-gray-900">Recoveries</dt><dd class="text-gray-600">{{ $event->currency }} {{ $event->recoveries }}</dd></div>
    <div><dt class="font-medium text-gray-900">Net loss</dt><dd class="text-gray-600">{{ $event->currency }} {{ $event->net_loss }}</dd></div>
    <div><dt class="font-medium text-gray-900">Source reference</dt><dd class="text-gray-600">{{ $event->source_reference ?: 'None' }}</dd></div>
    <div class="sm:col-span-2"><dt class="font-medium text-gray-900">Summary</dt><dd class="whitespace-pre-wrap text-gray-600">{{ $event->summary }}</dd></div>
    <div><dt class="font-medium text-gray-900">Reported by</dt><dd class="text-gray-600">{{ $event->reporter?->name }}</dd></div>
    <div><dt class="font-medium text-gray-900">Recorded</dt><dd class="text-gray-600">{{ $event->recorded_at->toDateTimeString() }}</dd></div>
</dl>
