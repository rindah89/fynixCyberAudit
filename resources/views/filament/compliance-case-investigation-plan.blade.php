<div style="display:grid;gap:var(--space-4);color:var(--gray-700)">
    <section><strong>{{ __('Investigation plan evidence') }}</strong><pre style="white-space:pre-wrap;overflow-wrap:anywhere;background:var(--gray-100);padding:var(--space-4);border-radius:var(--radius-sm)">{{ json_encode($plan->attributesToArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre></section>
    @if ($plan->review)
        <section><strong>{{ __('Independent review evidence') }}</strong><pre style="white-space:pre-wrap;overflow-wrap:anywhere;background:var(--gray-100);padding:var(--space-4);border-radius:var(--radius-sm)">{{ json_encode($plan->review->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre></section>
    @endif
</div>
