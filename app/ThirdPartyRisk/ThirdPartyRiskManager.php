<?php

namespace App\ThirdPartyRisk;

use App\Enums\RiskDomain;
use App\Enums\SurveyStatus;
use App\Enums\SurveyType;
use App\Enums\ThirdPartyRiskDecisionType;
use App\Enums\ThirdPartyRiskReviewOutcome;
use App\Models\Risk;
use App\Models\Survey;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorRiskAssessment;
use App\Models\VendorRiskDecision;
use App\Models\VendorRiskReview;
use App\Services\GovernanceIssueLifecycleManager;
use App\Services\GovernedEvidenceSnapshotter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ThirdPartyRiskManager
{
    public function mapRisk(Vendor $vendor, Risk $risk): Risk
    {
        return DB::transaction(function () use ($vendor, $risk) {
            $locked = Vendor::query()->lockForUpdate()->findOrFail($vendor->id);
            $governedRisk = Risk::query()->lockForUpdate()->findOrFail($risk->id);
            if ($governedRisk->domain !== RiskDomain::ThirdParty) {
                throw ValidationException::withMessages(['risk_id' => 'Only governed third-party risks may be linked to a vendor.']);
            }
            $locked->risks()->syncWithoutDetaching([$governedRisk->id]);

            return $governedRisk;
        });
    }

    public function assess(Vendor $vendor, User $actor, array $data): VendorRiskAssessment
    {
        return DB::transaction(function () use ($vendor, $actor, $data) {
            $locked = Vendor::query()->lockForUpdate()->findOrFail($vendor->id);
            $survey = isset($data['survey_id']) ? Survey::query()->lockForUpdate()->find($data['survey_id']) : null;
            if ($survey && ($survey->vendor_id !== $locked->id
                || $survey->type !== SurveyType::VENDOR_ASSESSMENT
                || $survey->status !== SurveyStatus::COMPLETED
                || $survey->risk_score === null
                || $survey->risk_score_calculated_at === null)) {
                throw ValidationException::withMessages(['survey_id' => 'The survey must be a completed, scored vendor assessment for this vendor.']);
            }
            $version = ((int) $locked->riskAssessments()->max('version')) + 1;

            return $locked->riskAssessments()->create($data + [
                'assessor_id' => $actor->id, 'version' => $version,
                'inherent_score' => $data['likelihood'] * $data['impact'],
                'residual_score' => $data['residual_likelihood'] * $data['residual_impact'],
                'survey_score_snapshot' => $survey?->risk_score, 'assessed_at' => now(),
            ]);
        });
    }

    public function decide(Vendor $vendor, User $actor, ThirdPartyRiskDecisionType $decision, array $data): VendorRiskDecision
    {
        return DB::transaction(function () use ($vendor, $actor, $decision, $data) {
            $locked = Vendor::query()->lockForUpdate()->findOrFail($vendor->id);
            $assessment = $locked->latestRiskAssessment()->lockForUpdate()->first();
            $risks = $locked->risks()->lockForUpdate()->orderBy('risks.id')->get();
            $riskIds = $risks->pluck('id')->all();
            $errors = [];
            if (! $assessment) {
                $errors['assessment'] = 'A current vendor risk assessment is required.';
            }
            if ($riskIds === []) {
                $errors['risks'] = 'At least one governed third-party risk must be linked.';
            } elseif ($risks->contains(fn (Risk $risk): bool => $risk->domain !== RiskDomain::ThirdParty)) {
                $errors['risks'] = 'Every linked risk must remain classified as third-party risk.';
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $locked->setRelation('risks', $risks);
            $snapshot = $locked->thirdPartyRiskSnapshot($assessment);

            return $locked->riskDecisions()->create([
                'vendor_risk_assessment_id' => $assessment->id, 'decided_by' => $actor->id,
                'decision' => $decision, 'rationale' => $data['rationale'], 'conditions' => $data['conditions'] ?? null,
                'assessment_version' => $assessment->version, 'residual_score' => $assessment->residual_score,
                'risk_ids' => $riskIds, 'governance_fingerprint' => $snapshot['fingerprint'],
                'expires_at' => $data['expires_at'] ?? null, 'next_review_at' => $data['next_review_at'] ?? null,
                'decided_at' => now(),
            ]);
        });
    }

    public function review(Vendor $vendor, User $actor, ThirdPartyRiskReviewOutcome $outcome, array $data): VendorRiskReview
    {
        $snapshotter = app(GovernedEvidenceSnapshotter::class);
        $snapshotBatch = Str::uuid()->toString();
        $retainedCopies = [];

        try {
            return DB::transaction(function () use ($vendor, $actor, $outcome, $data, $snapshotter, $snapshotBatch, &$retainedCopies) {
                $locked = Vendor::query()->lockForUpdate()->findOrFail($vendor->id);
                if (! $actor->can('Manage Third Party Risk')) {
                    abort(403, 'You cannot record third-party risk reviews.');
                }
                $decision = $locked->latestRiskDecision()->lockForUpdate()->first();
                if (! $decision || ! in_array($decision->decision, [ThirdPartyRiskDecisionType::Approved, ThirdPartyRiskDecisionType::ConditionallyApproved], true) || $decision->expires_at?->copy()->endOfDay()->isPast()) {
                    throw ValidationException::withMessages(['approval' => 'A current vendor risk approval is required before review.']);
                }
                $assessment = $locked->latestRiskAssessment()->lockForUpdate()->firstOrFail();
                $risks = $locked->risks()->lockForUpdate()->orderBy('risks.id')->get();
                $locked->setRelation('risks', $risks);
                if ($decision->governance_fingerprint !== $locked->thirdPartyRiskSnapshot($assessment)['fingerprint']) {
                    throw ValidationException::withMessages(['approval' => 'Vendor governance inputs changed after approval. Record a new decision before review.']);
                }
                $evidenceSnapshots = empty($data['evidence_attachment_ids']) ? [] : $snapshotter->snapshot(
                    $data['evidence_attachment_ids'], $actor, 'vendor-risk-review', $snapshotBatch, $retainedCopies,
                );
                $reviewedAt = now();
                $review = $locked->riskReviews()->create([
                    'vendor_risk_decision_id' => $decision->id, 'reviewed_by' => $actor->id,
                    'outcome' => $outcome, 'summary' => $data['summary'], 'evidence_reference' => $data['evidence_reference'] ?? null,
                    'assessment_version' => $decision->assessment_version, 'governance_fingerprint' => $decision->governance_fingerprint,
                    'next_review_at' => $data['next_review_at'], 'reviewed_at' => $reviewedAt,
                ]);
                foreach ($evidenceSnapshots as $snapshot) {
                    $review->evidence()->create($snapshot + ['linked_by' => $actor->id, 'linked_at' => $reviewedAt]);
                }
                if ($outcome !== ThirdPartyRiskReviewOutcome::Satisfactory) {
                    $issue = $review->issue()->create([
                        'vendor_id' => $locked->id, 'owner_id' => $locked->vendor_manager_id,
                        'title' => $outcome === ThirdPartyRiskReviewOutcome::Terminate ? 'Third-party relationship requires termination review' : 'Third-party risk review requires action',
                        'description' => $data['summary'], 'severity' => $outcome === ThirdPartyRiskReviewOutcome::Terminate ? 'critical' : 'high',
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
}
