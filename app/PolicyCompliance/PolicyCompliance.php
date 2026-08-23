<?php

namespace App\PolicyCompliance;

use App\Enums\PolicyAttestationOutcome;
use App\Models\PolicyAttestation;
use App\Models\PolicyException;
use App\Models\PolicyObligation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PolicyCompliance
{
    public function attest(
        PolicyObligation $obligation,
        User $attestor,
        PolicyAttestationOutcome $outcome,
        string $statement,
        ?string $evidenceReference = null,
        ?PolicyException $exception = null,
        ?Carbon $attestedAt = null,
    ): PolicyAttestation {
        $attestedAt ??= now();

        return DB::transaction(function () use ($obligation, $attestor, $outcome, $statement, $evidenceReference, $exception, $attestedAt) {
            $locked = PolicyObligation::query()->lockForUpdate()->findOrFail($obligation->id);
            $lockedException = $exception
                ? PolicyException::query()->lockForUpdate()->findOrFail($exception->id)
                : null;

            if (! $locked->is_active) {
                throw ValidationException::withMessages([
                    'policy_obligation_id' => 'Inactive obligations cannot be attested.',
                ]);
            }

            if ($lockedException && $lockedException->policy_id !== $locked->policy_id) {
                throw ValidationException::withMessages([
                    'policy_exception_id' => 'The exception must belong to the obligation policy.',
                ]);
            }

            if ($lockedException && ! $lockedException->isActive()) {
                throw ValidationException::withMessages([
                    'policy_exception_id' => 'The exception must be approved and currently in effect.',
                ]);
            }

            $attestation = $locked->attestations()->create([
                'attested_by' => $attestor->id,
                'policy_exception_id' => $lockedException?->id,
                'outcome' => $outcome,
                'statement' => $statement,
                'evidence_reference' => $evidenceReference,
                'attested_at' => $attestedAt,
            ]);

            $locked->forceFill([
                'last_outcome' => $outcome,
                'last_attested_at' => $attestedAt,
                'next_due_at' => $locked->frequency->nextDueAt($attestedAt),
            ])->save();

            return $attestation->load(['attestor', 'policyException']);
        });
    }
}
