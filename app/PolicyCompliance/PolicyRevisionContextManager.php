<?php

namespace App\PolicyCompliance;

use App\Models\Control;
use App\Models\Implementation;
use App\Models\Policy;
use App\Models\Risk;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class PolicyRevisionContextManager
{
    public function attachRisk(Policy $policy, Risk $risk): void
    {
        $this->mutate($policy, $risk, 'risks', true);
    }

    public function detachRisks(Policy $policy, iterable $risks): void
    {
        $this->detachMany($policy, $risks, 'risks', Risk::class);
    }

    public function attachControl(Policy $policy, Control $control): void
    {
        $this->mutate($policy, $control, 'controls', true);
    }

    public function detachControls(Policy $policy, iterable $controls): void
    {
        $this->detachMany($policy, $controls, 'controls', Control::class);
    }

    public function attachImplementation(Policy $policy, Implementation $implementation): void
    {
        $this->mutate($policy, $implementation, 'implementations', true);
    }

    public function detachImplementations(Policy $policy, iterable $implementations): void
    {
        $this->detachMany($policy, $implementations, 'implementations', Implementation::class);
    }

    private function mutate(Policy $policy, Model $related, string $relation, bool $attach): void
    {
        DB::transaction(function () use ($policy, $related, $relation, $attach): void {
            $lockedPolicy = Policy::query()->lockForUpdate()->findOrFail($policy->id);
            $lockedRelated = $related::query()->lockForUpdate()->findOrFail($related->getKey());
            $attach
                ? $lockedPolicy->{$relation}()->syncWithoutDetaching([$lockedRelated->getKey()])
                : $lockedPolicy->{$relation}()->detach([$lockedRelated->getKey()]);
        }, 3);
    }

    private function detachMany(Policy $policy, iterable $records, string $relation, string $modelClass): void
    {
        $ids = collect($records)->map(fn ($record): int => (int) ($record instanceof Model ? $record->getKey() : $record))
            ->unique()->sort()->values();
        if ($ids->isEmpty()) {
            return;
        }
        DB::transaction(function () use ($policy, $ids, $relation, $modelClass): void {
            $lockedPolicy = Policy::query()->lockForUpdate()->findOrFail($policy->id);
            $modelClass::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
            $lockedPolicy->{$relation}()->detach($ids->all());
        }, 3);
    }
}
