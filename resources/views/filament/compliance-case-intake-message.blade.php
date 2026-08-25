<div style="display:grid;gap:var(--space-4);color:var(--gray-700)">
    <section>
        <strong>{{ __('Message evidence') }}</strong>
        <pre style="white-space:pre-wrap;overflow-wrap:anywhere;background:var(--gray-100);padding:var(--space-4);border-radius:var(--radius-sm)">{{ json_encode($message->attributesToArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
    </section>
    @if ($message->acknowledgement)
        <section>
            <strong>{{ __('Exact-reporter acknowledgement evidence') }}</strong>
            <pre style="white-space:pre-wrap;overflow-wrap:anywhere;background:var(--gray-100);padding:var(--space-4);border-radius:var(--radius-sm)">{{ json_encode($message->acknowledgement->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
        </section>
    @endif
</div>
