<?php

namespace App\Services;

use App\Models\Audit;
use App\Models\AuditCloseoutSubmission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AuditTeamManager
{
    public function sync(Audit $audit, User $actor, array $memberIds): void
    {
        DB::transaction(function () use ($audit, $actor, $memberIds): void {
            $locked = Audit::query()->lockForUpdate()->findOrFail($audit->id);
            abort_unless($actor->can('update', $locked), 403);
            if (AuditCloseoutSubmission::freezesAudit($locked->id)) {
                throw ValidationException::withMessages([
                    'members' => 'Audit team membership is frozen while closeout is pending or approved.',
                ]);
            }

            $ids = collect($memberIds)->map(fn ($id): int => (int) $id)->filter()->unique()->sort()->values();
            $users = User::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->pluck('id');
            if ($users->count() !== $ids->count()) {
                throw ValidationException::withMessages(['members' => 'Every selected audit member must exist.']);
            }

            $locked->members()->sync($ids->all());
        }, 3);
    }
}
