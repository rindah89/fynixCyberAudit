<div class="space-y-4 text-sm">
    <h3 class="text-base font-semibold">{{ $title }}</h3>
    <p class="whitespace-pre-wrap">{{ $summary }}</p>
    <dl class="grid gap-2"><dt class="font-medium">SHA-256 fingerprint</dt><dd class="break-all font-mono">{{ $fingerprint }}</dd></dl>
    <pre class="max-h-96 overflow-auto whitespace-pre-wrap rounded-lg p-4 text-xs">{{ json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
</div>
