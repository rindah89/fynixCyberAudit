<?php

namespace App\Suite;

use App\Models\GovernanceException;
use App\Models\GovernanceStatement;
use Carbon\CarbonImmutable;

class GovernanceOversightService
{
    /** @return array<string, mixed> */
    public function report(bool $includeDetails = true): array
    {
        $now = CarbonImmutable::now('UTC');
        $freshAfter = $now->subHours((int) config('data_governance.freshness_hours', 26));
        $sources = [];
        foreach (config('data_governance.required_sources', []) as $source) {
            $binding = config('data_governance.bindings.'.$source);
            $latest = GovernanceStatement::query()->with('controlResults')->where('source', $source)->latest('occurred_at')->first();
            $bindingEnabled = is_array($binding)
                && ($binding['enabled'] ?? false)
                && ! empty($binding['tenant_id'])
                && ! empty($binding['webhook_id'])
                && ! empty($binding['secret']);
            $sources[$source] = [
                'binding' => $bindingEnabled ? 'enabled' : 'missing',
                'freshness' => ! $latest ? 'missing' : ($latest->occurred_at->lessThan($freshAfter) ? 'stale' : 'current'),
                'last_statement_at' => $latest?->occurred_at?->toIso8601String(),
                'effective_controls' => $latest?->controlResults->whereIn('status', ['effective', 'not_applicable'])->count() ?? 0,
                'total_controls' => count(config('data_governance.controls', [])),
                'open_exceptions' => GovernanceException::query()->where('source', $source)->where('status', 'open')->count(),
                'waived_exceptions' => GovernanceException::query()->where('source', $source)->where('status', 'waived')->count(),
            ];
        }
        $ready = collect($sources)->every(fn (array $source): bool => $source['binding'] === 'enabled' && $source['freshness'] === 'current' && $source['open_exceptions'] === 0);
        $hasWaivers = collect($sources)->contains(fn (array $source): bool => $source['waived_exceptions'] > 0);
        $report = ['status' => $ready ? ($hasWaivers ? 'conformant_with_waivers' : 'conformant') : 'attention_required', 'generated_at' => $now->toIso8601String(), 'sources' => $sources];
        if ($includeDetails) {
            $report['controls'] = config('data_governance.controls');
            $report['open_exceptions'] = GovernanceException::query()->whereIn('status', ['open', 'waived'])->orderByRaw("CASE severity WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")->orderBy('due_at')->get();
        }

        return $report;
    }
}
