<div class="space-y-4 text-sm" style="color: var(--gray-700)">
    <section class="rounded-lg p-4" style="background: var(--gray-100)">
        <div><strong>{{ $record->reference }}</strong> · {{ $record->release ? __('Released') : __('Active') }}</div>
        <div>{{ __('Issued by') }} {{ $record->issuer?->name }} · {{ $record->issued_at?->toDayDateTimeString() }}</div>
        <div class="mt-2">{{ $record->scope }}</div>
        <div class="mt-2"><strong>{{ __('Systems') }}:</strong> {{ implode(', ', $record->systems) }}</div>
        <div><strong>{{ __('Data categories') }}:</strong> {{ implode(', ', $record->data_categories) }}</div>
        <div><strong>{{ __('Legal basis reference') }}:</strong> {{ $record->legal_basis_reference ?? __('Not specified') }}</div>
        <div><strong>{{ __('Preservation starts') }}:</strong> {{ $record->preservation_start_at?->toDayDateTimeString() }}</div>
        <div class="mt-2 break-all"><strong>{{ __('Fingerprint') }}:</strong> {{ $record->fingerprint }}</div>
        <details class="mt-2"><summary>{{ __('Retained instruction evidence') }}</summary><pre class="mt-2 whitespace-pre-wrap">{{ json_encode($record->only(['issuer_snapshot', 'custodian_snapshot', 'case_snapshot', 'latest_event_snapshot']), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></details>
    </section>
    @foreach ($record->custodians as $custodian)
        <section class="rounded-lg p-4" style="background: var(--gray-100)">
            <div><strong>{{ $custodian->user?->name }}</strong> · {{ $custodian->acknowledgement ? __('Acknowledged') : __('Pending') }}</div>
            @if ($custodian->acknowledgement)
                <div>{{ $custodian->acknowledgement->statement }} · {{ $custodian->acknowledgement->acknowledged_at?->toDayDateTimeString() }}</div>
                @if ($custodian->acknowledgement->comment)<div>{{ $custodian->acknowledgement->comment }}</div>@endif
                <div class="break-all"><strong>{{ __('Fingerprint') }}:</strong> {{ $custodian->acknowledgement->fingerprint }}</div>
                <details class="mt-2"><summary>{{ __('Retained acknowledgement evidence') }}</summary><pre class="mt-2 whitespace-pre-wrap">{{ json_encode($custodian->acknowledgement->only(['hold_snapshot', 'recipient_snapshot', 'statement', 'comment', 'acknowledged_at', 'fingerprint']), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></details>
            @endif
        </section>
    @endforeach
    @if ($record->release)
        <section class="rounded-lg p-4" style="background: var(--gray-100)">
            <div><strong>{{ __('Released by') }} {{ $record->release->actor?->name }}</strong> · {{ $record->release->released_at?->toDayDateTimeString() }}</div>
            <div>{{ $record->release->summary }}</div>
            <div class="break-all"><strong>{{ __('Fingerprint') }}:</strong> {{ $record->release->fingerprint }}</div>
            <details class="mt-2"><summary>{{ __('Retained release evidence') }}</summary><pre class="mt-2 whitespace-pre-wrap">{{ json_encode($record->release->only(['actor_snapshot', 'hold_snapshot', 'custodian_acknowledgement_snapshot']), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></details>
        </section>
    @endif
</div>
