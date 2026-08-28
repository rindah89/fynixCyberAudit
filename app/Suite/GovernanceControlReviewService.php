<?php

namespace App\Suite;

use App\Models\DataProcessor;
use App\Models\DispositionReceipt;
use App\Models\GovernanceControlReview;
use App\Models\PrivacyRequest;
use App\Models\ProcessorRegisterCertification;
use App\Models\RecoveryEvidence;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class GovernanceControlReviewService
{
    /** @param array<string, mixed> $attributes */
    public function review(array $attributes, User $reviewer): GovernanceControlReview
    {
        return DB::transaction(function () use ($attributes, $reviewer): GovernanceControlReview {
            $resource = $this->resource($attributes['resource_type'], (int) $attributes['resource_id']);
            $statusField = $resource instanceof DataProcessor ? 'status' : 'review_status';
            if (! in_array($resource->{$statusField}, ['pending_review', 'rejected'], true)) {
                throw new InvalidArgumentException('Resource is not awaiting review.');
            }
            if ($resource instanceof PrivacyRequest && $resource->status !== 'closed') {
                throw new InvalidArgumentException('Privacy fulfillment must be complete before review.');
            }

            $decidedAt = now();
            $canonical = json_encode([
                'tenant_id' => $resource->tenant_id, 'source' => $resource->source,
                'resource_type' => $attributes['resource_type'], 'resource_id' => $resource->getKey(),
                'decision' => $attributes['decision'], 'reviewer_id' => $reviewer->getKey(),
                'review_evidence_ref' => $attributes['review_evidence_ref'],
                'review_evidence_sha256' => $attributes['review_evidence_sha256'],
                'notes' => $attributes['notes'] ?? null, 'decided_at' => $decidedAt->toISOString(),
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $digest = hash('sha256', $canonical);
            $resource->update([
                $statusField => $attributes['decision'], 'reviewed_by' => $reviewer->getKey(),
                'reviewed_at' => $decidedAt, 'review_digest' => $digest,
            ]);

            return GovernanceControlReview::create([
                'tenant_id' => $resource->tenant_id, 'source' => $resource->source,
                'resource_type' => $attributes['resource_type'], 'resource_id' => $resource->getKey(),
                'decision' => $attributes['decision'], 'reviewer_id' => $reviewer->getKey(),
                'review_evidence_ref' => $attributes['review_evidence_ref'],
                'review_evidence_sha256' => $attributes['review_evidence_sha256'],
                'notes' => $attributes['notes'] ?? null, 'review_digest' => $digest, 'decided_at' => $decidedAt,
            ]);
        });
    }

    /** @param array<string, mixed> $attributes */
    public function certifyProcessorRegister(array $attributes, User $reviewer): ProcessorRegisterCertification
    {
        return DB::transaction(function () use ($attributes, $reviewer): ProcessorRegisterCertification {
            $processors = DataProcessor::query()
                ->where(['tenant_id' => $attributes['tenant_id'], 'source' => $attributes['source'], 'active' => true])
                ->orderBy('name')->lockForUpdate()->get();
            if ($processors->count() !== (int) $attributes['expected_processor_count'] || $processors->isEmpty()) {
                throw new InvalidArgumentException('Processor inventory count does not match the reviewed register.');
            }
            if ($processors->contains(fn (DataProcessor $processor): bool => $processor->status !== 'approved' || $processor->review_due_at->isPast())) {
                throw new InvalidArgumentException('Every processor must be approved and within its review period.');
            }
            $inventoryDigest = hash('sha256', $processors->map(fn (DataProcessor $processor): array => [
                'id' => $processor->id, 'name' => $processor->name,
                'agreement_evidence_sha256' => $processor->agreement_evidence_sha256,
                'review_digest' => $processor->review_digest,
            ])->toJson(JSON_UNESCAPED_SLASHES));
            $decidedAt = now();
            $reviewDigest = hash('sha256', json_encode([
                'tenant_id' => $attributes['tenant_id'], 'source' => $attributes['source'],
                'processor_count' => $processors->count(), 'inventory_digest' => $inventoryDigest,
                'reviewer_id' => $reviewer->getKey(), 'valid_until' => $attributes['valid_until'],
                'review_evidence_sha256' => $attributes['review_evidence_sha256'],
                'decided_at' => $decidedAt->toISOString(),
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return ProcessorRegisterCertification::create([
                'tenant_id' => $attributes['tenant_id'], 'source' => $attributes['source'],
                'processor_count' => $processors->count(), 'inventory_digest' => $inventoryDigest,
                'reviewer_id' => $reviewer->getKey(), 'review_evidence_ref' => $attributes['review_evidence_ref'],
                'review_evidence_sha256' => $attributes['review_evidence_sha256'], 'review_digest' => $reviewDigest,
                'valid_until' => $attributes['valid_until'], 'decided_at' => $decidedAt,
            ]);
        });
    }

    private function resource(string $type, int $id): Model
    {
        return match ($type) {
            'processor' => DataProcessor::findOrFail($id),
            'recovery_evidence' => RecoveryEvidence::findOrFail($id),
            'disposition_receipt' => DispositionReceipt::findOrFail($id),
            'privacy_completion' => PrivacyRequest::findOrFail($id),
            default => throw new InvalidArgumentException('Unsupported review resource.'),
        };
    }
}
