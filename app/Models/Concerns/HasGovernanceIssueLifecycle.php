<?php

namespace App\Models\Concerns;

use App\Models\GovernanceIssueLifecycle;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use LogicException;

trait HasGovernanceIssueLifecycle
{
    public static function bootHasGovernanceIssueLifecycle(): void
    {
        static::updating(function ($issue): void {
            if ($issue->isDirty(['status', 'remediation_task_id'])) {
                throw new LogicException('Governance issue status and remediation links must change through the governed lifecycle.');
            }
        });

        static::deleting(function ($issue): void {
            if ($issue->lifecycle()->exists()) {
                throw new LogicException('Governance issues with lifecycle history cannot be deleted through product interfaces.');
            }
        });
    }

    public function lifecycle(): MorphOne
    {
        return $this->morphOne(GovernanceIssueLifecycle::class, 'issue');
    }
}
