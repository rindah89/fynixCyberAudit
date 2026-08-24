<div class="space-y-4 text-sm text-gray-900">
    <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
        <div><span class="text-gray-500">Fourth party</span><div>{{ $dependency->fourth_party_name }}</div></div>
        <div><span class="text-gray-500">Version</span><div>{{ $dependency->version }}</div></div>
        <div><span class="text-gray-500">Status</span><div>{{ $dependency->status->getLabel() }}</div></div>
        <div><span class="text-gray-500">Category</span><div>{{ $dependency->category->getLabel() }}</div></div>
        <div><span class="text-gray-500">Criticality</span><div>{{ $dependency->criticality->getLabel() }}</div></div>
        <div><span class="text-gray-500">Data access</span><div>{{ $dependency->data_access ? 'Yes' : 'No' }}</div></div>
        <div><span class="text-gray-500">Affected service</span><div>{{ data_get($dependency->governance_snapshot, 'business_service.code', 'Not mapped') }} — {{ data_get($dependency->governance_snapshot, 'business_service.name', '') }}</div></div>
        <div><span class="text-gray-500">Concentration</span><div>{{ str($concentration['concentration_band'])->title() }} ({{ $concentration['primary_vendor_count'] }} primary vendors)</div></div>
        <div><span class="text-gray-500">Recorded by</span><div>{{ $dependency->recorder?->name }} at {{ $dependency->recorded_at?->toDateTimeString() }}</div></div>
    </div>
    <div><span class="text-gray-500">Service description</span><div class="whitespace-pre-wrap">{{ $dependency->service_description }}</div></div>
    <div><span class="text-gray-500">Source reference</span><div>{{ $dependency->source_reference ?: 'Not provided' }}</div></div>
    <div><span class="text-gray-500">Rationale</span><div class="whitespace-pre-wrap">{{ $dependency->rationale }}</div></div>
</div>
