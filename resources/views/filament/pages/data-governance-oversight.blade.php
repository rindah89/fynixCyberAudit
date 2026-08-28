<x-filament-panels::page>
    <div class="space-y-6">
        <div class="rounded-xl border p-5" style="border-color: var(--border, #DADAD4); background: var(--surface, #fff);">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide" style="color: var(--ink-muted, #5B5B56);">Suite assurance status</p>
                    <p class="mt-1 text-2xl font-semibold">{{ str_replace('_', ' ', ucfirst($report['status'])) }}</p>
                    <p class="mt-1 text-sm" style="color: var(--ink-muted, #5B5B56);">Generated {{ $report['generated_at'] }}. Current means evidence received within the configured freshness window.</p>
                </div>
                <a href="{{ $exceptionsUrl }}" class="rounded-lg px-4 py-2 text-sm font-semibold" style="background: var(--primary-500, #167D6A); color: white;">Manage exceptions</a>
            </div>
        </div>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($report['sources'] as $source => $coverage)
                <div class="rounded-xl border p-4" style="border-color: var(--border, #DADAD4); background: var(--surface, #fff);">
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="font-semibold uppercase">{{ $source }}</h2>
                        <span class="rounded-full px-2 py-1 text-xs font-semibold {{ $coverage['freshness'] === 'current' ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800' }}">{{ $coverage['freshness'] }}</span>
                    </div>
                    <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                        <div><dt style="color: var(--ink-muted, #5B5B56);">Binding</dt><dd class="font-medium">{{ $coverage['binding'] }}</dd></div>
                        <div><dt style="color: var(--ink-muted, #5B5B56);">Effective</dt><dd class="font-medium">{{ $coverage['effective_controls'] }}/{{ $coverage['total_controls'] }}</dd></div>
                        <div><dt style="color: var(--ink-muted, #5B5B56);">Exceptions</dt><dd class="font-medium">{{ $coverage['open_exceptions'] }} open / {{ $coverage['waived_exceptions'] }} waived</dd></div>
                        <div><dt style="color: var(--ink-muted, #5B5B56);">Last report</dt><dd class="font-medium">{{ $coverage['last_statement_at'] ?? 'Never' }}</dd></div>
                    </dl>
                    <div class="mt-4 border-t pt-4" style="border-color: var(--border, #DADAD4);">
                        <h3 class="text-xs font-semibold uppercase tracking-wide" style="color: var(--ink-muted, #5B5B56);">Operational oversight</h3>
                        <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
                            <div><dt style="color: var(--ink-muted, #5B5B56);">Overdue privacy</dt><dd class="font-medium {{ $coverage['operability']['overdue_privacy_requests'] > 0 ? 'text-red-700' : '' }}">{{ $coverage['operability']['overdue_privacy_requests'] }}</dd></div>
                            <div><dt style="color: var(--ink-muted, #5B5B56);">Active holds</dt><dd class="font-medium">{{ $coverage['operability']['active_legal_holds'] }}</dd></div>
                            <div><dt style="color: var(--ink-muted, #5B5B56);">Privacy review</dt><dd class="font-medium {{ $coverage['operability']['pending_privacy_reviews'] > 0 ? 'text-amber-700' : '' }}">{{ $coverage['operability']['pending_privacy_reviews'] }} pending</dd></div>
                            <div><dt style="color: var(--ink-muted, #5B5B56);">Disposition review</dt><dd class="font-medium {{ $coverage['operability']['pending_disposition_reviews'] > 0 ? 'text-amber-700' : '' }}">{{ $coverage['operability']['pending_disposition_reviews'] }} pending</dd></div>
                            <div><dt style="color: var(--ink-muted, #5B5B56);">Processor review</dt><dd class="font-medium {{ $coverage['operability']['pending_processor_reviews'] > 0 ? 'text-amber-700' : '' }}">{{ $coverage['operability']['pending_processor_reviews'] }} pending</dd></div>
                            <div><dt style="color: var(--ink-muted, #5B5B56);">Recovery review</dt><dd class="font-medium {{ $coverage['operability']['pending_recovery_reviews'] > 0 ? 'text-amber-700' : '' }}">{{ $coverage['operability']['pending_recovery_reviews'] }} pending</dd></div>
                            <div><dt style="color: var(--ink-muted, #5B5B56);">Restore evidence</dt><dd class="font-medium {{ $coverage['operability']['current_approved_restore_evidence'] ? 'text-green-700' : 'text-red-700' }}">{{ $coverage['operability']['current_approved_restore_evidence'] ? 'Current' : 'Missing or stale' }}</dd></div>
                            <div><dt style="color: var(--ink-muted, #5B5B56);">Processor register</dt><dd class="font-medium {{ $coverage['operability']['processor_register_certified'] ? 'text-green-700' : 'text-red-700' }}">{{ $coverage['operability']['processor_register_certified'] ? 'Certified' : 'Not certified' }}</dd></div>
                            <div><dt style="color: var(--ink-muted, #5B5B56);">Disposition receipts</dt><dd class="font-medium">{{ $coverage['operability']['disposition_receipts'] }}</dd></div>
                            <div><dt style="color: var(--ink-muted, #5B5B56);">Invalid reviews</dt><dd class="font-medium {{ $coverage['operability']['invalid_or_tampered_reviews'] > 0 ? 'text-red-700' : '' }}">{{ $coverage['operability']['invalid_or_tampered_reviews'] }}</dd></div>
                        </dl>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="overflow-hidden rounded-xl border" style="border-color: var(--border, #DADAD4); background: var(--surface, #fff);">
            <div class="border-b px-5 py-4" style="border-color: var(--border, #DADAD4);"><h2 class="font-semibold">Open and waived exceptions</h2></div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead style="background: var(--surface-muted, #F5F5F1);"><tr><th class="px-4 py-3">Application</th><th class="px-4 py-3">Control</th><th class="px-4 py-3">Severity</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Owner</th><th class="px-4 py-3">Due</th><th class="px-4 py-3">Reason</th></tr></thead>
                    <tbody>
                    @forelse ($report['open_exceptions'] as $exception)
                        <tr class="border-t" style="border-color: var(--border, #DADAD4);"><td class="px-4 py-3 font-medium">{{ $exception->source }}</td><td class="px-4 py-3">{{ $exception->control_id }}</td><td class="px-4 py-3">{{ $exception->severity }}</td><td class="px-4 py-3">{{ $exception->status }}</td><td class="px-4 py-3">{{ $exception->owner ?? 'Unassigned' }}</td><td class="px-4 py-3">{{ $exception->due_at?->toIso8601String() ?? 'Not set' }}</td><td class="px-4 py-3">{{ $exception->reason }}</td></tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-8 text-center" style="color: var(--ink-muted, #5B5B56);">No current exceptions.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-filament-panels::page>
