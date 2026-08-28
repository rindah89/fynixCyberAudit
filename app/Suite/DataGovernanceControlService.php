<?php

namespace App\Suite;

use App\Models\DataProcessor;
use App\Models\DispositionReceipt;
use App\Models\LegalHold;
use App\Models\PrivacyRequest;
use App\Models\ProcessorRegisterCertification;
use App\Models\RecoveryEvidence;
use App\Models\RetentionPolicy;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

class DataGovernanceControlService
{
    private const PRIVACY_RIGHTS = ['access', 'correction', 'deletion', 'restriction', 'objection', 'portability'];

    public function openPrivacyRequest(array $attributes): PrivacyRequest
    {
        if (! in_array($attributes['right'] ?? null, self::PRIVACY_RIGHTS, true)) {
            throw new InvalidArgumentException('Unsupported privacy right.');
        }
        $requestedAt = CarbonImmutable::parse($attributes['requested_at'] ?? now());

        return PrivacyRequest::create(array_merge($attributes, ['status' => 'open', 'requested_at' => $requestedAt, 'due_at' => $requestedAt->addDays(30)]));
    }

    public function closePrivacyRequest(PrivacyRequest $request, string $evidenceRef, string $evidenceSha256): PrivacyRequest
    {
        if ($request->status !== 'open' || $evidenceRef === '' || ! preg_match('/^[a-f0-9]{64}$/', $evidenceSha256)) {
            throw new InvalidArgumentException('Completion evidence is required.');
        }
        $request->update(['status' => 'closed', 'completed_at' => now(), 'evidence_ref' => $evidenceRef, 'evidence_sha256' => $evidenceSha256, 'review_status' => 'pending_review']);

        return $request->refresh();
    }

    public function registerProcessor(array $attributes): DataProcessor
    {
        $countries = $attributes['processing_countries'] ?? [];
        if (($countries !== [] && blank($attributes['transfer_mechanism'] ?? null)) || ! preg_match('/^[a-f0-9]{64}$/', (string) ($attributes['agreement_evidence_sha256'] ?? ''))) {
            throw new InvalidArgumentException('A transfer mechanism is required for cross-border processing.');
        }

        return DataProcessor::updateOrCreate(
            ['tenant_id' => $attributes['tenant_id'], 'source' => $attributes['source'], 'name' => $attributes['name']],
            array_merge($attributes, ['status' => 'pending_review']),
        );
    }

    public function defineRetentionPolicy(array $attributes): RetentionPolicy
    {
        if (($attributes['retention_days'] ?? 0) < 1 || ! in_array($attributes['disposition_action'] ?? null, ['delete', 'anonymize', 'archive'], true)) {
            throw new InvalidArgumentException('Retention and disposition policy is invalid.');
        }

        return RetentionPolicy::updateOrCreate(
            ['tenant_id' => $attributes['tenant_id'], 'source' => $attributes['source'], 'record_class' => $attributes['record_class']],
            array_merge($attributes, ['active' => true]),
        );
    }

    public function placeLegalHold(RetentionPolicy $policy, string $reason): LegalHold
    {
        if (blank($reason)) {
            throw new InvalidArgumentException('A legal-hold reason is required.');
        }

        return $policy->legalHolds()->create(['reason' => $reason, 'placed_at' => now()]);
    }

    public function releaseLegalHold(LegalHold $hold): LegalHold
    {
        if ($hold->released_at !== null) {
            throw new InvalidArgumentException('Legal hold is already released.');
        }
        $hold->update(['released_at' => now()]);

        return $hold->refresh();
    }

    public function mayDispose(RetentionPolicy $policy): bool
    {
        return $policy->active && ! $policy->legalHolds()->whereNull('released_at')->exists();
    }

    public function recordDisposition(RetentionPolicy $policy, array $attributes): DispositionReceipt
    {
        $recordCreatedAt = CarbonImmutable::parse($attributes['record_created_at'] ?? now()->addSecond());
        $eligibleAt = $recordCreatedAt->addDays($policy->retention_days);
        if (! $this->mayDispose($policy) || $eligibleAt->isFuture()) {
            throw new InvalidArgumentException('Disposition is not eligible or is blocked by a legal hold.');
        }
        if (($attributes['action'] ?? null) !== $policy->disposition_action || blank($attributes['record_ref'] ?? null) || blank($attributes['evidence_ref'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/', (string) ($attributes['evidence_sha256'] ?? ''))) {
            throw new InvalidArgumentException('Disposition must match policy and include record and evidence references.');
        }

        unset($attributes['record_created_at']);

        return DispositionReceipt::create(array_merge($attributes, [
            'tenant_id' => $policy->tenant_id,
            'source' => $policy->source,
            'retention_policy_id' => $policy->getKey(),
            'eligible_at' => $eligibleAt,
            'disposed_at' => now(),
            'review_status' => 'pending_review',
        ]));
    }

    public function recordRecoveryEvidence(array $attributes): RecoveryEvidence
    {
        if (($attributes['outcome'] ?? null) !== 'successful' || blank($attributes['evidence_ref'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/', (string) ($attributes['evidence_sha256'] ?? ''))) {
            throw new InvalidArgumentException('Successful recovery evidence with a reference is required.');
        }

        return RecoveryEvidence::create(array_merge($attributes, ['review_status' => 'pending_review']));
    }

    public function hasCurrentRecoveryEvidence(string $tenantId, string $source, DateTimeInterface $at): bool
    {
        return RecoveryEvidence::query()
            ->where(['tenant_id' => $tenantId, 'source' => $source, 'kind' => 'restore_drill', 'outcome' => 'successful', 'review_status' => 'approved'])
            ->where('occurred_at', '>=', CarbonImmutable::instance($at)->subMonths(3))
            ->where('occurred_at', '<=', CarbonImmutable::instance($at))
            ->exists();
    }

    public function hasCurrentProcessorRegister(string $tenantId, string $source, DateTimeInterface $at): bool
    {
        $processors = DataProcessor::query()
            ->where(['tenant_id' => $tenantId, 'source' => $source, 'status' => 'approved'])
            ->whereDate('review_due_at', '>=', CarbonImmutable::instance($at)->toDateString())
            ->orderBy('name')->get();
        if ($processors->isEmpty()) {
            return false;
        }
        $inventoryDigest = hash('sha256', $processors->map(fn (DataProcessor $processor): array => [
            'id' => $processor->id, 'name' => $processor->name,
            'agreement_evidence_sha256' => $processor->agreement_evidence_sha256,
            'review_digest' => $processor->review_digest,
        ])->toJson(JSON_UNESCAPED_SLASHES));

        return ProcessorRegisterCertification::query()
            ->where(['tenant_id' => $tenantId, 'source' => $source, 'processor_count' => $processors->count(), 'inventory_digest' => $inventoryDigest])
            ->whereDate('valid_until', '>=', CarbonImmutable::instance($at)->toDateString())
            ->exists();
    }
}
