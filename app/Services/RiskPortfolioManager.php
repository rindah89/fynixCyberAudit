<?php

namespace App\Services;

use App\Enums\Applicability;
use App\Enums\ImplementationStatus;
use App\Enums\RiskDomain;
use App\Enums\RiskGovernanceDecision;
use App\Models\Asset;
use App\Models\BusinessService;
use App\Models\Control;
use App\Models\Implementation;
use App\Models\Risk;
use App\Models\RiskGovernanceProfile;
use App\Models\RiskGovernanceReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RiskPortfolioManager
{
    public function profile(Risk $risk, array $data): RiskGovernanceProfile
    {
        return DB::transaction(function () use ($risk, $data) {
            app(RiskPortfolioContextManager::class)->lockSnapshotBoundary();
            $locked = Risk::query()->lockForUpdate()->findOrFail($risk->id);
            $this->validateContext($locked, $data);

            return $locked->governanceProfile()->updateOrCreate(['risk_id' => $locked->id], $data);
        });
    }

    public function review(Risk $risk, User $actor, RiskGovernanceDecision $decision, array $data): RiskGovernanceReview
    {
        $snapshotter = app(GovernedEvidenceSnapshotter::class);
        $snapshotBatch = Str::uuid()->toString();
        $retainedCopies = [];

        try {
            return DB::transaction(function () use ($risk, $actor, $decision, $data, $snapshotter, $snapshotBatch, &$retainedCopies) {
                app(RiskPortfolioContextManager::class)->lockSnapshotBoundary();
                $locked = Risk::query()->lockForUpdate()->findOrFail($risk->id);
                if (! $actor->can('Manage Risk Portfolio')) {
                    abort(403, 'You cannot record risk governance reviews.');
                }
                $profile = $locked->governanceProfile()->lockForUpdate()->first();
                if (! $profile) {
                    throw ValidationException::withMessages(['profile' => 'A governance profile is required before review.']);
                }
                $this->lockContextGraph($locked, $profile);
                $this->validateContext($locked, $profile->toArray());
                if ($decision === RiskGovernanceDecision::Accepted && $locked->residual_risk > $profile->appetite_threshold) {
                    throw ValidationException::withMessages(['decision' => 'A risk above appetite cannot be accepted. Select a treatment decision.']);
                }

                $evidenceSnapshots = empty($data['evidence_attachment_ids']) ? [] : $snapshotter->snapshot(
                    $data['evidence_attachment_ids'], $actor, 'risk-governance-review', $snapshotBatch, $retainedCopies,
                );
                $snapshot = $locked->portfolioGovernanceSnapshot($profile);
                $reviewedAt = now();
                $review = $locked->governanceReviews()->create([
                    'risk_governance_profile_id' => $profile->id, 'reviewed_by' => $actor->id,
                    'decision' => $decision, 'summary' => $data['summary'], 'evidence_reference' => $data['evidence_reference'] ?? null,
                    'domain_snapshot' => $locked->domain, 'inherent_score_snapshot' => $locked->inherent_risk,
                    'residual_score_snapshot' => $locked->residual_risk, 'appetite_threshold_snapshot' => $profile->appetite_threshold,
                    'asset_ids_snapshot' => collect($snapshot['assets'])->pluck('id')->all(),
                    'implementation_ids_snapshot' => collect($snapshot['implementations'])->pluck('id')->all(),
                    'business_service_id_snapshot' => $profile->business_service_id,
                    'governance_snapshot' => $snapshot,
                    'governance_fingerprint' => $snapshot['fingerprint'], 'next_review_at' => $data['next_review_at'], 'reviewed_at' => $reviewedAt,
                ]);
                foreach ($evidenceSnapshots as $evidenceSnapshot) {
                    $review->evidence()->create($evidenceSnapshot + ['linked_by' => $actor->id, 'linked_at' => $reviewedAt]);
                }

                if ($decision !== RiskGovernanceDecision::Accepted) {
                    $issue = $review->issue()->create([
                        'risk_id' => $locked->id, 'owner_id' => $profile->owner_id,
                        'title' => 'Risk treatment requires action', 'description' => $data['summary'],
                        'severity' => $locked->residual_risk >= 20 ? 'critical' : ($locked->residual_risk >= 12 ? 'high' : 'medium'),
                        'status' => 'open',
                    ]);
                    app(GovernanceIssueLifecycleManager::class)->register($issue, $actor);
                }

                return $review->load(['issue', 'evidence.linkedBy:id,name']);
            }, 3);
        } catch (\Throwable $exception) {
            $snapshotter->cleanup($retainedCopies);

            throw $exception;
        }
    }

    private function validateContext(Risk $risk, array $data): void
    {
        if (! in_array($risk->domain, [RiskDomain::Enterprise, RiskDomain::Operational, RiskDomain::Technology], true)) {
            throw ValidationException::withMessages(['domain' => 'This workflow supports enterprise, operational, and technology risks only.']);
        }
        $errors = [];
        if ($risk->domain === RiskDomain::Enterprise && blank($data['strategic_objective'] ?? null)) {
            $errors['strategic_objective'] = 'Enterprise risks require strategic objective context.';
        }
        if ($risk->domain === RiskDomain::Operational && empty($data['business_service_id'])) {
            $errors['business_service_id'] = 'Operational risks require a mapped business service.';
        } elseif ($risk->domain === RiskDomain::Operational && ! BusinessService::query()->whereKey($data['business_service_id'])->where('status', 'active')->exists()) {
            $errors['business_service_id'] = 'Operational risks require an active business service.';
        }
        if ($risk->domain === RiskDomain::Technology && ! $risk->assets()->where('is_active', true)->exists()) {
            $errors['assets'] = 'Technology risks require at least one mapped active asset.';
        }
        if ($risk->domain === RiskDomain::Technology && ! $risk->implementations()
            ->where('implementations.status', ImplementationStatus::FULL)
            ->whereHas('controls', fn ($query) => $query->where('controls.applicability', Applicability::APPLICABLE))
            ->exists()) {
            $errors['implementations'] = 'Technology risks require at least one fully implemented control implementation linked to an applicable control.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    public function lockContextGraph(Risk $risk, RiskGovernanceProfile $profile): void
    {
        $assetIds = DB::table('asset_risk')->where('risk_id', $risk->id)->lockForUpdate()->pluck('asset_id');
        Asset::query()->whereKey($assetIds)->lockForUpdate()->get();

        $implementationIds = DB::table('implementation_risk')->where('risk_id', $risk->id)->lockForUpdate()->pluck('implementation_id');
        Implementation::query()->whereKey($implementationIds)->lockForUpdate()->get();
        $controlIds = DB::table('control_implementation')->whereIn('implementation_id', $implementationIds)->lockForUpdate()->pluck('control_id');
        Control::query()->whereKey($controlIds)->lockForUpdate()->get();

        if ($profile->business_service_id) {
            BusinessService::query()->whereKey($profile->business_service_id)->lockForUpdate()->first();
        }

        $risk->unsetRelation('assets')->unsetRelation('implementations');
        $profile->unsetRelation('businessService');
    }
}
