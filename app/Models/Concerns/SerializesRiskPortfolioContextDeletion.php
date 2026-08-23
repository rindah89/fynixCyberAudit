<?php

namespace App\Models\Concerns;

use App\Services\RiskPortfolioContextManager;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

trait SerializesRiskPortfolioContextDeletion
{
    use SoftDeletes {
        performDeleteOnModel as private performContextDeleteWithoutMutex;
        restore as private restoreContextWithoutMutex;
    }

    protected function performDeleteOnModel(): mixed
    {
        return DB::transaction(function () {
            app(RiskPortfolioContextManager::class)->lockSnapshotBoundary();

            return $this->performContextDeleteWithoutMutex();
        }, 3);
    }

    public function restore(): bool
    {
        return DB::transaction(function (): bool {
            app(RiskPortfolioContextManager::class)->lockSnapshotBoundary();

            return $this->restoreContextWithoutMutex();
        }, 3);
    }
}
