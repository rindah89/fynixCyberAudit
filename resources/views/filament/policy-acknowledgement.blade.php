<div class="space-y-4 text-sm text-gray-900">
    <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
        <div><span class="text-gray-500">Assigned user</span><div>{{ $assignment->user?->name }} ({{ $assignment->user?->email }})</div></div>
        <div><span class="text-gray-500">Status</span><div>{{ str($assignment->acknowledgement_status)->replace('_', ' ')->title() }}</div></div>
        <div><span class="text-gray-500">Due</span><div>{{ $assignment->campaign->due_at?->toDateTimeString() }}</div></div>
        <div><span class="text-gray-500">Assigned</span><div>{{ $assignment->assigned_at?->toDateTimeString() }}</div></div>
        <div><span class="text-gray-500">Acknowledged</span><div>{{ $assignment->acknowledgement?->acknowledged_at?->toDateTimeString() ?? 'Not acknowledged' }}</div></div>
        <div><span class="text-gray-500">Client reference</span><div>{{ $assignment->acknowledgement?->client_reference ?: 'Not provided' }}</div></div>
    </div>
    <div><span class="text-gray-500">Statement</span><div>{{ $assignment->acknowledgement?->statement ?: 'Not acknowledged' }}</div></div>
    <div><span class="text-gray-500">Comment</span><div class="whitespace-pre-wrap">{{ $assignment->acknowledgement?->comment ?: 'No comment' }}</div></div>
    <div><span class="text-gray-500">Policy fingerprint</span><div class="break-all font-mono text-xs">{{ $assignment->campaign->policy_fingerprint }}</div></div>
    <div><span class="text-gray-500">Assigned policy</span><div>{{ data_get($assignment->campaign->policy_snapshot, 'code') }} — {{ data_get($assignment->campaign->policy_snapshot, 'name') }}</div></div>
    <div><span class="text-gray-500">Policy body snapshot</span><div class="whitespace-pre-wrap">{{ strip_tags((string) data_get($assignment->campaign->policy_snapshot, 'body')) }}</div></div>
</div>
