<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Control;
use App\Models\Implementation;
use App\Models\Risk;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RiskPortfolioContextManager
{
    /** @param list<int|string> $assetIds */
    public function syncAssets(Risk $risk, array $assetIds): void
    {
        $this->mutate(function () use ($risk, $assetIds): void {
            $locked = Risk::query()->lockForUpdate()->findOrFail($risk->id);
            $ids = $this->normalizeIds($assetIds);
            Asset::query()->whereKey($ids)->lockForUpdate()->get();
            $locked->assets()->sync($ids);
        });
    }

    public function attachAsset(Risk $risk, Asset $asset): void
    {
        $this->mutate(function () use ($risk, $asset): void {
            $locked = Risk::query()->lockForUpdate()->findOrFail($risk->id);
            Asset::query()->whereKey($asset->id)->lockForUpdate()->firstOrFail();
            $locked->assets()->syncWithoutDetaching([$asset->id]);
        });
    }

    /** @param iterable<Model> $assets */
    public function detachAssets(Risk $risk, iterable $assets): int
    {
        return $this->mutate(function () use ($risk, $assets): int {
            $locked = Risk::query()->lockForUpdate()->findOrFail($risk->id);
            $ids = collect($assets)->map(fn (Model $asset): int => (int) $asset->getKey())->unique()->sort()->values();
            Asset::query()->whereKey($ids)->lockForUpdate()->get();

            return $locked->assets()->detach($ids);
        });
    }

    /** @param list<int|string> $implementationIds */
    public function syncImplementations(Risk $risk, array $implementationIds): void
    {
        $this->mutate(function () use ($risk, $implementationIds): void {
            $locked = Risk::query()->lockForUpdate()->findOrFail($risk->id);
            $ids = $this->normalizeIds($implementationIds);
            Implementation::query()->whereKey($ids)->lockForUpdate()->get();
            $locked->implementations()->sync($ids);
        });
    }

    public function attachImplementation(Risk $risk, Implementation $implementation): void
    {
        $this->mutate(function () use ($risk, $implementation): void {
            $locked = Risk::query()->lockForUpdate()->findOrFail($risk->id);
            Implementation::query()->whereKey($implementation->id)->lockForUpdate()->firstOrFail();
            $locked->implementations()->syncWithoutDetaching([$implementation->id]);
        });
    }

    /** @param iterable<Model> $implementations */
    public function detachImplementations(Risk $risk, iterable $implementations): int
    {
        return $this->mutate(function () use ($risk, $implementations): int {
            $locked = Risk::query()->lockForUpdate()->findOrFail($risk->id);
            $ids = collect($implementations)->map(fn (Model $implementation): int => (int) $implementation->getKey())->unique()->sort()->values();
            Implementation::query()->whereKey($ids)->lockForUpdate()->get();

            return $locked->implementations()->detach($ids);
        });
    }

    /** @param list<int|string> $controlIds */
    public function syncControls(Implementation $implementation, array $controlIds): void
    {
        $this->mutate(function () use ($implementation, $controlIds): void {
            $locked = Implementation::query()->lockForUpdate()->findOrFail($implementation->id);
            $ids = $this->normalizeIds($controlIds);
            Control::query()->whereKey($ids)->lockForUpdate()->get();
            $locked->controls()->sync($ids);
        });
    }

    public function attachControl(Implementation $implementation, Control $control): void
    {
        $this->mutate(function () use ($implementation, $control): void {
            $locked = Implementation::query()->lockForUpdate()->findOrFail($implementation->id);
            Control::query()->whereKey($control->id)->lockForUpdate()->firstOrFail();
            $locked->controls()->syncWithoutDetaching([$control->id]);
        });
    }

    /** @param iterable<Model|int|string> $controls */
    public function attachControls(Implementation $implementation, iterable $controls): void
    {
        $this->mutate(function () use ($implementation, $controls): void {
            $locked = Implementation::query()->lockForUpdate()->findOrFail($implementation->id);
            $ids = $this->normalizeIds($controls);
            Control::query()->whereKey($ids)->lockForUpdate()->get();
            $locked->controls()->syncWithoutDetaching($ids);
        });
    }

    /** @param iterable<Model> $controls */
    public function detachControls(Implementation $implementation, iterable $controls): int
    {
        return $this->mutate(function () use ($implementation, $controls): int {
            $locked = Implementation::query()->lockForUpdate()->findOrFail($implementation->id);
            $ids = collect($controls)->map(fn (Model $control): int => (int) $control->getKey())->unique()->sort()->values();
            Control::query()->whereKey($ids)->lockForUpdate()->get();

            return $locked->controls()->detach($ids);
        });
    }

    public function lockSnapshotBoundary(): void
    {
        DB::table('risk_portfolio_context_mutex')->where('id', 1)->lockForUpdate()->first();
    }

    /** @template TReturn */
    private function mutate(callable $callback): mixed
    {
        return DB::transaction(function () use ($callback) {
            $this->lockSnapshotBoundary();

            return $callback();
        }, 3);
    }

    /** @param iterable<Model|int|string> $values */
    private function normalizeIds(iterable $values): Collection
    {
        return collect($values)
            ->map(fn (Model|int|string $value): int => (int) ($value instanceof Model ? $value->getKey() : $value))
            ->unique()->sort()->values();
    }
}
