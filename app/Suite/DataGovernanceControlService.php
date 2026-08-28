<?php

namespace App\Suite;

use App\Models\DataProcessor;
use App\Models\DispositionReceipt;
use App\Models\LegalHold;
use App\Models\PrivacyRequest;
use App\Models\ProcessorInventoryRun;
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
        $attributes['data_categories'] = $this->normalizedStrings($attributes['data_categories'] ?? []);
        $attributes['processing_countries'] = $this->normalizedStrings($attributes['processing_countries'] ?? []);
        $countries = $attributes['processing_countries'];
        if (($countries !== [] && blank($attributes['transfer_mechanism'] ?? null)) || ! preg_match('/^[a-f0-9]{64}$/', (string) ($attributes['agreement_evidence_sha256'] ?? ''))) {
            throw new InvalidArgumentException('A transfer mechanism is required for cross-border processing.');
        }

        $identity = ['tenant_id' => $attributes['tenant_id'], 'source' => $attributes['source'], 'name' => $attributes['name']];
        $processor = DataProcessor::firstOrNew($identity);
        $material = array_merge($attributes, ['active' => true]);
        $changed = ! $processor->exists || collect($material)->contains(
            fn ($value, $key): bool => $this->comparable($processor->{$key}) !== $this->comparable($value)
        );
        $processor->fill($material);
        if ($changed) {
            $processor->fill(['status' => 'pending_review', 'reviewed_by' => null, 'reviewed_at' => null, 'review_digest' => null]);
        }
        $processor->save();

        return $processor->refresh();
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

    public function placeLegalHold(RetentionPolicy $policy, string $reason, ?string $recordRef = null, ?string $sourceHoldRef = null): LegalHold
    {
        if (blank($reason)) {
            throw new InvalidArgumentException('A legal-hold reason is required.');
        }

        if ($sourceHoldRef !== null) {
            return $policy->legalHolds()->firstOrCreate(
                ['source_hold_ref' => $sourceHoldRef],
                ['reason' => $reason, 'record_ref' => $recordRef, 'placed_at' => now()]
            );
        }

        return $policy->legalHolds()->create(['reason' => $reason, 'record_ref' => $recordRef, 'placed_at' => now()]);
    }

    public function releaseLegalHold(LegalHold $hold): LegalHold
    {
        if ($hold->released_at !== null) {
            throw new InvalidArgumentException('Legal hold is already released.');
        }
        $hold->update(['released_at' => now()]);

        return $hold->refresh();
    }

    public function mayDispose(RetentionPolicy $policy, ?string $recordRef = null): bool
    {
        $holds = $policy->legalHolds()->whereNull('released_at');
        if ($recordRef !== null) {
            $holds->where(fn ($query) => $query->whereNull('record_ref')->orWhere('record_ref', $recordRef));
        }

        return $policy->active && ! $holds->exists();
    }

    public function recordDisposition(RetentionPolicy $policy, array $attributes): DispositionReceipt
    {
        $recordCreatedAt = CarbonImmutable::parse($attributes['record_created_at'] ?? now()->addSecond());
        $eligibleAt = $recordCreatedAt->addDays($policy->retention_days);
        if (! $this->mayDispose($policy, (string) ($attributes['record_ref'] ?? '')) || $eligibleAt->isFuture()) {
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
        if (! $this->hasCurrentProcessorInventoryRun($at)) {
            return false;
        }
        $processors = DataProcessor::query()
            ->where(['tenant_id' => $tenantId, 'source' => $source, 'status' => 'approved', 'active' => true])
            ->whereDate('review_due_at', '>=', CarbonImmutable::instance($at)->toDateString())
            ->orderBy('name')->get();
        if ($processors->isEmpty()) {
            return false;
        }
        $inventoryDigest = hash('sha256', $processors->map(fn (DataProcessor $processor): array => [
            ...$processor->materialEvidence(), 'review_digest' => $processor->review_digest,
        ])->toJson(JSON_UNESCAPED_SLASHES));

        return ProcessorRegisterCertification::query()
            ->where(['tenant_id' => $tenantId, 'source' => $source, 'processor_count' => $processors->count(), 'inventory_digest' => $inventoryDigest])
            ->whereDate('valid_until', '>=', CarbonImmutable::instance($at)->toDateString())
            ->exists();
    }

    public function hasCurrentProcessorInventoryRun(DateTimeInterface $at): bool
    {
        $latestRun = ProcessorInventoryRun::query()->latest('completed_at')->latest('id')->first();

        return $latestRun !== null && $latestRun->status === 'successful'
            && $latestRun->completed_at->gte(CarbonImmutable::instance($at)->subHours((int) config('data_governance.freshness_hours', 26)));
    }

    private function normalizedStrings(array $values): array
    {
        $values = array_values(array_unique(array_map('strval', $values)));
        sort($values, SORT_STRING);

        return $values;
    }

    private function comparable(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_array($value)) {
            return $this->normalizedStrings($value);
        }

        return $value;
    }
}
