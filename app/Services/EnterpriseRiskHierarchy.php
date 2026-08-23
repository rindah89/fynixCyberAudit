<?php

namespace App\Services;

use App\Enums\RiskDomain;
use App\Models\Risk;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EnterpriseRiskHierarchy
{
    private const MAX_DEPTH = 100;

    private const MAX_NODES = 10000;

    public function assignParent(Risk $risk, ?int $parentRiskId, User $actor): Risk
    {
        return DB::transaction(function () use ($risk, $parentRiskId, $actor): Risk {
            DB::table('risk_hierarchy_mutexes')->where('id', 1)->lockForUpdate()->first();
            $locked = Risk::query()->lockForUpdate()->findOrFail($risk->id);
            $this->assertGovernedEnterprise($locked, 'risk', $parentRiskId !== null);

            if ($locked->parent_risk_id === $parentRiskId) {
                return $locked->load('parentRisk');
            }

            if ($parentRiskId === null) {
                $previousParentId = $locked->parent_risk_id;
                $locked->update(['parent_risk_id' => null]);
                $this->recordChange($locked, $previousParentId, null, $actor);

                return $locked->load('parentRisk');
            }
            if ($parentRiskId === $locked->id) {
                throw ValidationException::withMessages(['parent_risk_id' => 'A risk cannot be its own parent.']);
            }

            $parent = Risk::query()->lockForUpdate()->findOrFail($parentRiskId);
            $this->assertGovernedEnterprise($parent, 'parent_risk_id');
            $this->assertNoCycle($locked, $parent);
            $previousParentId = $locked->parent_risk_id;
            $locked->update(['parent_risk_id' => $parent->id]);
            $this->recordChange($locked, $previousParentId, $parent->id, $actor);

            return $locked->load('parentRisk');
        }, 3);
    }

    private function recordChange(Risk $risk, ?int $previousParentId, ?int $parentId, User $actor): void
    {
        $risk->hierarchyChanges()->create([
            'previous_parent_risk_id' => $previousParentId,
            'parent_risk_id' => $parentId,
            'changed_by' => $actor->id,
            'changed_at' => now(),
        ]);
    }

    public function rollup(Risk $root): array
    {
        $this->assertGovernedEnterprise($root, 'risk', false);
        $ids = $this->descendantIds($root->id);
        $risks = Risk::query()->with('governanceProfile:id,risk_id,appetite_threshold')->whereKey([$root->id, ...$ids])->get();
        $active = $risks->where('is_active', true)->values();
        $scores = $active->pluck('residual_risk')->map(fn ($score): int => (int) $score);

        return [
            'root_risk_id' => $root->id,
            'risk_count' => $active->count(),
            'descendant_count' => $active->where('id', '!=', $root->id)->count(),
            'residual_score_sum' => $scores->sum(),
            'residual_score_average' => $scores->isEmpty() ? 0.0 : round($scores->average(), 2),
            'residual_score_maximum' => $scores->max() ?? 0,
            'above_appetite_count' => $active->filter(fn (Risk $risk): bool => $risk->governanceProfile && $risk->residual_risk > $risk->governanceProfile->appetite_threshold)->count(),
            'score_band_counts' => [
                'critical' => $scores->filter(fn (int $score): bool => $score >= 20)->count(),
                'high' => $scores->filter(fn (int $score): bool => $score >= 12 && $score < 20)->count(),
                'medium' => $scores->filter(fn (int $score): bool => $score >= 6 && $score < 12)->count(),
                'low' => $scores->filter(fn (int $score): bool => $score < 6)->count(),
            ],
            'basis' => 'current_active_residual_scores',
            'generated_at' => now()->toIso8601String(),
        ];
    }

    public function boundedRollup(Risk $root): array
    {
        try {
            return ['available' => true] + $this->rollup($root);
        } catch (ValidationException $exception) {
            return ['available' => false, 'error' => collect($exception->errors())->flatten()->first()];
        }
    }

    /** @return list<int> */
    public function descendantIds(int $rootId): array
    {
        $all = collect();
        $frontier = collect([$rootId]);
        $depth = 0;
        while ($frontier->isNotEmpty()) {
            $next = Risk::query()->whereIn('parent_risk_id', $frontier)->pluck('id')->diff($all);
            if ($next->isEmpty()) {
                break;
            }
            $depth++;
            if ($depth > self::MAX_DEPTH) {
                throw ValidationException::withMessages(['hierarchy' => 'Risk roll-up exceeds the supported depth of '.self::MAX_DEPTH.'.']);
            }
            $all = $all->merge($next)->unique();
            if ($all->count() > self::MAX_NODES) {
                throw ValidationException::withMessages(['hierarchy' => 'Risk roll-up exceeds the supported size of '.self::MAX_NODES.' descendants.']);
            }
            $frontier = $next;
        }

        return $all->map(fn ($id): int => (int) $id)->values()->all();
    }

    private function assertGovernedEnterprise(Risk $risk, string $field, bool $requireActive = true): void
    {
        if ($risk->domain !== RiskDomain::Enterprise || ($requireActive && ! $risk->is_active) || ! $risk->governanceProfile()->exists()) {
            throw ValidationException::withMessages([$field => 'Enterprise hierarchy requires a governed enterprise risk; parent assignments also require an active risk.']);
        }
    }

    private function assertNoCycle(Risk $risk, Risk $candidate): void
    {
        $seen = collect([$candidate->id]);
        $current = $candidate;
        while ($current->parent_risk_id !== null) {
            if ($current->parent_risk_id === $risk->id) {
                throw ValidationException::withMessages(['parent_risk_id' => 'This parent would create a hierarchy cycle.']);
            }
            if ($seen->contains($current->parent_risk_id)) {
                throw ValidationException::withMessages(['parent_risk_id' => 'The existing hierarchy contains a cycle.']);
            }
            $seen->push($current->parent_risk_id);
            $current = Risk::query()->lockForUpdate()->findOrFail($current->parent_risk_id);
        }
    }
}
