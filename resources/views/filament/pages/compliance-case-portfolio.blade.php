<x-filament-panels::page>
    <p class="mb-4 text-sm text-gray-500">
        Counts and durations are derived only from cases the current caller can view. This page does not expose allegation or evidence content, misconduct trends, legal exposure, investigator performance, or effectiveness inference.
    </p>
    <form method="get" class="mb-6 flex flex-wrap items-end gap-3">
        <label class="text-sm">{{ __('Opened from') }}
            <input class="mt-1 block rounded-lg border-gray-300" type="date" name="opened_from" value="{{ $filters['opened_from'] ?? '' }}">
        </label>
        <label class="text-sm">{{ __('Opened to') }}
            <input class="mt-1 block rounded-lg border-gray-300" type="date" name="opened_to" value="{{ $filters['opened_to'] ?? '' }}">
        </label>
        <x-filament::button type="submit">{{ __('Apply bounded window') }}</x-filament::button>
    </form>
    <div class="grid gap-4 md:grid-cols-4">
        <div class="rounded-[var(--radius-xl)] border border-[var(--gray-200)] bg-[var(--color-white)] p-6 shadow-[var(--shadow-card)]">
            <div class="text-sm text-gray-500">Visible cases</div>
            <div class="text-2xl font-semibold">{{ $portfolio['total'] }}</div>
        </div>
        <div class="rounded-[var(--radius-xl)] border border-[var(--gray-200)] bg-[var(--color-white)] p-6 shadow-[var(--shadow-card)]">
            <h3 class="mb-2 font-medium">{{ __('By phase') }}</h3>
            <ul class="space-y-1 text-sm">
                @foreach ($portfolio['by_phase'] as $phase => $count)
                    <li class="flex justify-between"><span>{{ $phase }}</span><span>{{ $count }}</span></li>
                @endforeach
            </ul>
        </div>
        <div class="rounded-[var(--radius-xl)] border border-[var(--gray-200)] bg-[var(--color-white)] p-6 shadow-[var(--shadow-card)]">
            <div class="text-sm text-gray-500">Closed</div>
            <div class="text-2xl font-semibold">{{ $portfolio['closed'] }}</div>
        </div>
        <div class="rounded-[var(--radius-xl)] border border-[var(--gray-200)] bg-[var(--color-white)] p-6 shadow-[var(--shadow-card)]">
            <div class="text-sm text-gray-500">Reopened</div>
            <div class="text-2xl font-semibold">{{ $portfolio['reopened'] }}</div>
        </div>
        <div class="rounded-[var(--radius-xl)] border border-[var(--gray-200)] bg-[var(--color-white)] p-6 shadow-[var(--shadow-card)]">
            <div class="text-sm text-gray-500">Overdue milestones</div>
            <div class="text-2xl font-semibold">{{ $portfolio['overdue_milestones'] }}</div>
        </div>
        <div class="rounded-[var(--radius-xl)] border border-[var(--gray-200)] bg-[var(--color-white)] p-6 shadow-[var(--shadow-card)]">
            <div class="text-sm text-gray-500">Open holds</div>
            <div class="text-2xl font-semibold">{{ $portfolio['open_holds'] }}</div>
        </div>
        <div class="rounded-[var(--radius-xl)] border border-[var(--gray-200)] bg-[var(--color-white)] p-6 shadow-[var(--shadow-card)]">
            <div class="text-sm text-gray-500">Open issues</div>
            <div class="text-2xl font-semibold">{{ $portfolio['open_issues'] }}</div>
        </div>
        <div class="rounded-[var(--radius-xl)] border border-[var(--gray-200)] bg-[var(--color-white)] p-6 shadow-[var(--shadow-card)]">
            <div class="text-sm text-gray-500">Average open days</div>
            <div class="text-2xl font-semibold">{{ $portfolio['average_open_days'] ?? '—' }}</div>
        </div>
    </div>
    <div class="mt-6 grid gap-4 md:grid-cols-2">
        <div class="rounded-[var(--radius-xl)] border border-[var(--gray-200)] bg-[var(--color-white)] p-6 shadow-[var(--shadow-card)]">
            <h3 class="mb-2 font-medium">By status</h3>
            <ul class="space-y-1 text-sm">
                @foreach ($portfolio['by_status'] as $status => $count)
                    <li class="flex justify-between"><span>{{ $status }}</span><span>{{ $count }}</span></li>
                @endforeach
            </ul>
        </div>
        <div class="rounded-[var(--radius-xl)] border border-[var(--gray-200)] bg-[var(--color-white)] p-6 shadow-[var(--shadow-card)]">
            <h3 class="mb-2 font-medium">Open age bands</h3>
            <ul class="space-y-1 text-sm">
                <li class="flex justify-between"><span>0-7 days</span><span>{{ $portfolio['age_bands']['0_7'] }}</span></li>
                <li class="flex justify-between"><span>8-30 days</span><span>{{ $portfolio['age_bands']['8_30'] }}</span></li>
                <li class="flex justify-between"><span>31-90 days</span><span>{{ $portfolio['age_bands']['31_90'] }}</span></li>
                <li class="flex justify-between"><span>91+ days</span><span>{{ $portfolio['age_bands']['91_plus'] }}</span></li>
            </ul>
        </div>
        <div class="rounded-[var(--radius-xl)] border border-[var(--gray-200)] bg-[var(--color-white)] p-6 shadow-[var(--shadow-card)] md:col-span-2">
            <h3 class="mb-2 font-medium">Review outcomes</h3>
            <ul class="space-y-1 text-sm">
                @foreach ($portfolio['review_outcomes'] as $decision => $count)
                    <li class="flex justify-between"><span>Review decision {{ $decision }}</span><span>{{ $count }}</span></li>
                @endforeach
            </ul>
        </div>
    </div>
</x-filament-panels::page>
