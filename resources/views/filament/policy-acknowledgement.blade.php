<div class="space-y-4 text-sm text-gray-900">
    <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
        <div><span class="text-gray-500">Assigned user</span><div>{{ $assignment->user?->name }} ({{ $assignment->user?->email }})</div></div>
        <div><span class="text-gray-500">Status</span><div>{{ str($assignment->acknowledgement_status)->replace('_', ' ')->title() }}</div></div>
        <div><span class="text-gray-500">Due</span><div>{{ $assignment->campaign->due_at?->toDateTimeString() }}</div></div>
        <div><span class="text-gray-500">Assigned</span><div>{{ $assignment->assigned_at?->toDateTimeString() }}</div></div>
        <div><span class="text-gray-500">In-app notification delivered</span><div>{{ $assignment->delivery?->delivered_at?->toDateTimeString() ?? 'Not delivered' }}</div></div>
        <div><span class="text-gray-500">Acknowledged</span><div>{{ $assignment->acknowledgement?->acknowledged_at?->toDateTimeString() ?? 'Not acknowledged' }}</div></div>
        <div><span class="text-gray-500">Client reference</span><div>{{ $assignment->acknowledgement?->client_reference ?: 'Not provided' }}</div></div>
    </div>
    <div><span class="text-gray-500">Statement</span><div>{{ $assignment->acknowledgement?->statement ?: 'Not acknowledged' }}</div></div>
    <div><span class="text-gray-500">Comment</span><div class="whitespace-pre-wrap">{{ $assignment->acknowledgement?->comment ?: 'No comment' }}</div></div>
    <div><span class="text-gray-500">Policy fingerprint</span><div class="break-all font-mono text-xs">{{ $assignment->campaign->policy_fingerprint }}</div></div>
    @if ($assignment->delivery)
        <div><span class="text-gray-500">Notification identity</span><div class="break-all font-mono text-xs">{{ $assignment->delivery->notification_id }}</div></div>
        <div><span class="text-gray-500">Delivery fingerprint</span><div class="break-all font-mono text-xs">{{ $assignment->delivery->fingerprint }}</div></div>
        <div><span class="text-gray-500">Immutable delivery snapshot</span><pre class="mt-2 max-h-80 overflow-auto whitespace-pre-wrap rounded-lg border p-3 text-xs">{{ json_encode(['recipient' => $assignment->delivery->recipient_snapshot, 'campaign' => $assignment->delivery->campaign_snapshot], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></div>
    @endif
    @foreach ($assignment->reminders->sortBy('delivered_at') as $reminder)
        <div class="rounded-lg border p-3">
            <div class="font-medium">{{ $reminder->type->getLabel() }} in-app reminder</div>
            <div>Delivered {{ $reminder->delivered_at?->toDateTimeString() }} · channel {{ $reminder->channel }}</div>
            <div class="mt-2 break-all font-mono text-xs">Notification {{ $reminder->notification_id }}</div>
            <div class="mt-2 break-all font-mono text-xs">SHA-256 {{ $reminder->fingerprint }}</div>
            <details class="mt-2">
                <summary class="cursor-pointer font-medium">Immutable reminder snapshot</summary>
                <pre class="mt-2 max-h-80 overflow-auto whitespace-pre-wrap rounded-lg border p-3 text-xs">{{ json_encode(['recipient' => $reminder->recipient_snapshot, 'campaign' => $reminder->campaign_snapshot], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            </details>
        </div>
    @endforeach
    @if ($assignment->escalation)
        <div><span class="text-gray-500">Policy-owner escalation delivered</span><div>{{ $assignment->escalation->delivered_at->toDateTimeString() }}</div></div>
        <div><span class="text-gray-500">Escalation fingerprint</span><div class="break-all font-mono text-xs">{{ $assignment->escalation->fingerprint }}</div></div>
    @endif
    @foreach ($assignment->knowledgeCheckAttempts->sortBy('version') as $attempt)
        <div class="rounded-lg border p-3">
            <div class="font-medium">Comprehension check attempt {{ $attempt->version }} — {{ $attempt->passed ? 'Passed' : 'Not passed' }}</div>
            <div>Score {{ $attempt->score_percentage }}% · submitted {{ $attempt->submitted_at?->toDateTimeString() }}</div>
            <div class="mt-2 break-all font-mono text-xs">SHA-256 {{ $attempt->fingerprint }}</div>
            <details class="mt-2">
                <summary class="cursor-pointer font-medium">Immutable scoring snapshot</summary>
                <pre class="mt-2 max-h-80 overflow-auto whitespace-pre-wrap rounded-lg border p-3 text-xs">{{ json_encode(['answers' => $attempt->answers_snapshot, 'questions' => $attempt->question_snapshot], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            </details>
        </div>
    @endforeach
    <div><span class="text-gray-500">Assigned policy</span><div>{{ data_get($assignment->campaign->policy_snapshot, 'code') }} — {{ data_get($assignment->campaign->policy_snapshot, 'name') }}</div></div>
    <div><span class="text-gray-500">Policy body snapshot</span><div class="whitespace-pre-wrap">{{ strip_tags((string) data_get($assignment->campaign->policy_snapshot, 'body')) }}</div></div>
</div>
