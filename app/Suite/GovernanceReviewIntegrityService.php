<?php

namespace App\Suite;

use App\Models\DataProcessor;
use App\Models\DispositionReceipt;
use App\Models\GovernanceControlReview;
use App\Models\PrivacyRequest;
use App\Models\RecoveryEvidence;
use App\Models\RetentionRunEvidence;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class GovernanceReviewIntegrityService
{
    /** @param array<string, mixed> $review */
    public function digest(Model $resource, string $resourceType, array $review): string
    {
        return hash('sha256', json_encode([
            'tenant_id' => $resource->tenant_id, 'source' => $resource->source,
            'resource_type' => $resourceType, 'resource_id' => $resource->getKey(),
            'decision' => $review['decision'], 'reviewer_id' => $review['reviewer_id'],
            'review_evidence_ref' => $review['review_evidence_ref'],
            'review_evidence_sha256' => $review['review_evidence_sha256'],
            'resource_evidence' => $this->snapshot($resource),
            'notes' => $review['notes'] ?? null,
            'decided_at' => $this->date($review['decided_at']),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function approved(Model $resource, string $resourceType): bool
    {
        $review = GovernanceControlReview::query()
            ->where(['tenant_id' => $resource->tenant_id, 'source' => $resource->source,
                'resource_type' => $resourceType, 'resource_id' => $resource->getKey(), 'decision' => 'approved'])
            ->latest('decided_at')->latest('id')->first();
        if ($review === null || ! hash_equals((string) $resource->review_digest, (string) $review->review_digest)) {
            return false;
        }

        return hash_equals($review->review_digest, $this->digest($resource, $resourceType, [
            'decision' => $review->decision, 'reviewer_id' => $review->reviewer_id,
            'review_evidence_ref' => $review->review_evidence_ref,
            'review_evidence_sha256' => $review->review_evidence_sha256,
            'notes' => $review->notes, 'decided_at' => $review->decided_at,
        ]));
    }

    /** @return array<string, mixed> */
    private function snapshot(Model $resource): array
    {
        return match (true) {
            $resource instanceof DataProcessor => $resource->materialEvidence(),
            $resource instanceof RecoveryEvidence => [
                'kind' => $resource->kind, 'occurred_at' => $this->date($resource->occurred_at),
                'outcome' => $resource->outcome, 'evidence_ref' => $resource->evidence_ref,
                'evidence_sha256' => $resource->evidence_sha256,
            ],
            $resource instanceof PrivacyRequest => [
                'subject_ref' => $resource->subject_ref, 'right' => $resource->right,
                'lawful_basis' => $resource->lawful_basis, 'requested_at' => $this->date($resource->requested_at),
                'due_at' => $this->date($resource->due_at), 'completed_at' => $this->date($resource->completed_at),
                'evidence_ref' => $resource->evidence_ref, 'evidence_sha256' => $resource->evidence_sha256,
            ],
            $resource instanceof DispositionReceipt => [
                'retention_policy_id' => $resource->retention_policy_id, 'record_ref' => $resource->record_ref,
                'eligible_at' => $this->date($resource->eligible_at), 'disposed_at' => $this->date($resource->disposed_at),
                'action' => $resource->action, 'evidence_ref' => $resource->evidence_ref,
                'evidence_sha256' => $resource->evidence_sha256,
            ],
            $resource instanceof RetentionRunEvidence => [
                'source_run_ref' => $resource->source_run_ref,
                'schema_fingerprint' => $resource->schema_fingerprint,
                'schedule_sha256' => $resource->schedule_sha256,
                'policy_count' => $resource->policy_count,
                'eligible_count' => $resource->eligible_count,
                'disposed_count' => $resource->disposed_count,
                'held_count' => $resource->held_count,
                'preserved_policy_count' => $resource->preserved_policy_count,
                'pending_outbox_count' => $resource->pending_outbox_count,
                'outcome' => $resource->outcome,
                'occurred_at' => $this->date($resource->occurred_at),
                'evidence_ref' => $resource->evidence_ref,
                'evidence_sha256' => $resource->evidence_sha256,
            ],
            default => throw new InvalidArgumentException('Unsupported review evidence resource.'),
        };
    }

    private function date(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface ? $value->format('Y-m-d\TH:i:sP') : null;
    }
}
