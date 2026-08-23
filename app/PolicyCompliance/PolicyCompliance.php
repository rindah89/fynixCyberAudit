<?php

namespace App\PolicyCompliance;

use App\Enums\PolicyAttestationOutcome;
use App\Models\Policy;
use App\Models\PolicyAttestation;
use App\Models\PolicyException;
use App\Models\PolicyObligation;
use App\Models\User;
use App\Services\GovernedEvidenceSnapshotter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
        array $evidenceAttachmentIds = [],
    ): PolicyAttestation {
        $attestedAt ??= now();
        $snapshotter = app(GovernedEvidenceSnapshotter::class);
        $snapshotBatch = Str::uuid()->toString();
        $retainedCopies = [];

        try {
            return DB::transaction(function () use ($obligation, $attestor, $outcome, $statement, $evidenceReference, $exception, $attestedAt, $evidenceAttachmentIds, $snapshotter, $snapshotBatch, &$retainedCopies) {
                $locked = PolicyObligation::query()->lockForUpdate()->findOrFail($obligation->id);
                $policy = Policy::query()->lockForUpdate()->findOrFail($locked->policy_id);
                $locked->setRelation('policy', $policy);
                if (! $attestor->can('attest', $locked)) {
                    abort(403, 'You cannot attest this policy obligation.');
                }
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
                $evidenceSnapshots = $evidenceAttachmentIds === [] ? [] : $snapshotter->snapshot(
                    $evidenceAttachmentIds, $attestor, 'policy-attestation', $snapshotBatch, $retainedCopies,
                );

                $attestation = $locked->attestations()->create([
                    'attested_by' => $attestor->id,
                    'policy_exception_id' => $lockedException?->id,
                    'outcome' => $outcome,
                    'statement' => $statement,
                    'evidence_reference' => $evidenceReference,
                    'attested_at' => $attestedAt,
                ]);
                foreach ($evidenceSnapshots as $snapshot) {
                    $attestation->evidence()->create($snapshot + ['linked_by' => $attestor->id, 'linked_at' => $attestedAt]);
                }

                $locked->update([
                    'last_outcome' => $outcome,
                    'last_attested_at' => $attestedAt,
                    'next_due_at' => $locked->frequency->nextDueAt($attestedAt),
                ]);

                return $attestation->load(['attestor', 'policyException', 'evidence.linkedBy:id,name']);
            }, 3);
        } catch (\Throwable $exception) {
            $snapshotter->cleanup($retainedCopies);

            throw $exception;
        }
    }
}
