<?php

namespace App\Suite;

use App\Models\DataProcessor;
use App\Models\DispositionReceipt;
use App\Models\LegalHold;
use App\Models\PrivacyRequest;
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

    public function closePrivacyRequest(PrivacyRequest $request, string $evidenceRef): PrivacyRequest
    {
        if ($request->status !== 'open' || $evidenceRef === '') {
            throw new InvalidArgumentException('Completion evidence is required.');
        }
        $request->update(['status' => 'closed', 'completed_at' => now(), 'evidence_ref' => $evidenceRef]);

        return $request->refresh();
    }

    public function registerProcessor(array $attributes): DataProcessor
    {
        $countries = $attributes['processing_countries'] ?? [];
        if ($countries !== [] && blank($attributes['transfer_mechanism'] ?? null)) {
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
        if (($attributes['action'] ?? null) !== $policy->disposition_action || blank($attributes['record_ref'] ?? null) || blank($attributes['evidence_ref'] ?? null)) {
            throw new InvalidArgumentException('Disposition must match policy and include record and evidence references.');
        }

        unset($attributes['record_created_at']);

        return DispositionReceipt::create(array_merge($attributes, [
            'retention_policy_id' => $policy->getKey(),
            'eligible_at' => $eligibleAt,
            'disposed_at' => now(),
        ]));
    }

    public function recordRecoveryEvidence(array $attributes): RecoveryEvidence
    {
        if (($attributes['outcome'] ?? null) !== 'successful' || blank($attributes['evidence_ref'] ?? null)) {
            throw new InvalidArgumentException('Successful recovery evidence with a reference is required.');
        }

        return RecoveryEvidence::create($attributes);
    }

    public function hasCurrentRecoveryEvidence(string $tenantId, string $source, DateTimeInterface $at): bool
    {
        return RecoveryEvidence::query()
            ->where(['tenant_id' => $tenantId, 'source' => $source, 'kind' => 'restore_drill', 'outcome' => 'successful'])
            ->where('occurred_at', '>=', CarbonImmutable::instance($at)->subMonths(3))
            ->where('occurred_at', '<=', CarbonImmutable::instance($at))
            ->exists();
    }

    public function hasCurrentProcessorRegister(string $tenantId, string $source, DateTimeInterface $at): bool
    {
        return DataProcessor::query()
            ->where(['tenant_id' => $tenantId, 'source' => $source, 'status' => 'approved'])
            ->whereDate('review_due_at', '>=', CarbonImmutable::instance($at)->toDateString())
            ->exists();
    }
}
