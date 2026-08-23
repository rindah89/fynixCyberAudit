<?php

namespace App\AiGovernance;

use App\Enums\AiGovernanceDecisionType;
use App\Enums\AiMonitoringOutcome;
use App\Models\AiGovernanceDecision;
use App\Models\AiMonitoringReview;
use App\Models\AiRiskAssessment;
use App\Models\AiSystem;
use App\Models\AiUseCase;
use App\Models\User;
use App\Services\GovernanceIssueLifecycleManager;
use App\Services\GovernedEvidenceSnapshotter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AiGovernanceManager
{
    public function assess(AiUseCase $useCase, User $actor, array $data): AiRiskAssessment
    {
        return DB::transaction(function () use ($useCase, $actor, $data) {
            $locked = AiUseCase::query()->lockForUpdate()->findOrFail($useCase->id);
            $version = ((int) $locked->assessments()->max('version')) + 1;

            return $locked->assessments()->create($data + [
                'assessor_id' => $actor->id, 'version' => $version,
                'inherent_score' => $data['likelihood'] * $data['impact'],
                'residual_score' => $data['residual_likelihood'] * $data['residual_impact'],
                'assessed_at' => now(),
            ]);
        });
    }

    public function decide(AiUseCase $useCase, User $actor, AiGovernanceDecisionType $decision, array $data): AiGovernanceDecision
    {
        return DB::transaction(function () use ($useCase, $actor, $decision, $data) {
            $locked = AiUseCase::query()->lockForUpdate()->findOrFail($useCase->id);
            $assessment = $locked->latestAssessment()->lockForUpdate()->first();
            if (! $assessment) {
                throw ValidationException::withMessages(['assessment' => 'A current AI risk assessment is required.']);
            }
            $this->lockGovernanceGraph($locked);
            $controlsCount = $locked->controls->count();
            $risksCount = $locked->risks->count();
            $errors = [];
            if ($decision === AiGovernanceDecisionType::Approved && $controlsCount === 0) {
                $errors['controls'] = 'At least one control must be mapped before approval.';
            }
            if ($decision === AiGovernanceDecisionType::Approved && $risksCount === 0) {
                $errors['risks'] = 'At least one governed risk must be mapped before approval.';
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $snapshot = $locked->governanceSnapshot($assessment);

            $record = $locked->decisions()->create([
                'ai_risk_assessment_id' => $assessment->id, 'decided_by' => $actor->id,
                'decision' => $decision, 'rationale' => $data['rationale'], 'conditions' => $data['conditions'] ?? null,
                'assessment_version' => $assessment->version, 'residual_score' => $assessment->residual_score,
                'controls_count' => $controlsCount, 'risks_count' => $risksCount,
                'control_ids' => $snapshot['controlIds'], 'risk_ids' => $snapshot['riskIds'],
                'system_snapshot' => $snapshot['systemSnapshot'], 'use_case_snapshot' => $snapshot['useCaseSnapshot'],
                'governance_fingerprint' => $snapshot['fingerprint'],
                'expires_at' => $data['expires_at'] ?? null, 'decided_at' => now(),
            ]);
            if ($decision === AiGovernanceDecisionType::Approved) {
                $locked->update(['next_monitoring_at' => $data['next_monitoring_at']]);
            }

            return $record;
        });
    }

    public function monitor(AiUseCase $useCase, User $actor, AiMonitoringOutcome $outcome, array $data): AiMonitoringReview
    {
        $snapshotter = app(GovernedEvidenceSnapshotter::class);
        $snapshotBatch = Str::uuid()->toString();
        $retainedCopies = [];

        try {
            return DB::transaction(function () use ($useCase, $actor, $outcome, $data, $snapshotter, $snapshotBatch, &$retainedCopies) {
                $locked = AiUseCase::query()->lockForUpdate()->findOrFail($useCase->id);
                if (! $actor->can('Manage AI Governance')) {
                    abort(403, 'You cannot record AI monitoring reviews.');
                }
                $decision = $locked->latestDecision()->lockForUpdate()->first();
                if (! $decision || $decision->decision !== AiGovernanceDecisionType::Approved || $decision->expires_at?->copy()->endOfDay()->isPast()) {
                    throw ValidationException::withMessages(['approval' => 'A current approval is required before monitoring review.']);
                }
                $assessment = $locked->latestAssessment()->lockForUpdate()->firstOrFail();
                $this->lockGovernanceGraph($locked);
                if ($decision->governance_fingerprint !== $locked->governanceSnapshot($assessment)['fingerprint']) {
                    throw ValidationException::withMessages(['approval' => 'Governance inputs changed after approval. Record a new approval before monitoring.']);
                }
                $evidenceSnapshots = empty($data['evidence_attachment_ids']) ? [] : $snapshotter->snapshot(
                    $data['evidence_attachment_ids'],
                    $actor,
                    'ai-monitoring-review',
                    $snapshotBatch,
                    $retainedCopies,
                );
                $reviewedAt = now();
                $review = $locked->monitoringReviews()->create([
                    'ai_governance_decision_id' => $decision->id,
                    'reviewed_by' => $actor->id, 'outcome' => $outcome,
                    'assessment_version' => $decision->assessment_version, 'governance_fingerprint' => $decision->governance_fingerprint,
                    'performance_summary' => $data['performance_summary'], 'incidents_count' => $data['incidents_count'] ?? 0,
                    'complaints_count' => $data['complaints_count'] ?? 0, 'evidence_reference' => $data['evidence_reference'] ?? null,
                    'next_review_at' => $data['next_review_at'], 'reviewed_at' => $reviewedAt,
                ]);
                foreach ($evidenceSnapshots as $snapshot) {
                    $review->evidence()->create($snapshot + ['linked_by' => $actor->id, 'linked_at' => $reviewedAt]);
                }
                $locked->update(['next_monitoring_at' => $data['next_review_at']]);

                if ($outcome !== AiMonitoringOutcome::Satisfactory) {
                    $issue = $review->issue()->create([
                        'ai_use_case_id' => $locked->id, 'owner_id' => $locked->owner_id,
                        'title' => $outcome === AiMonitoringOutcome::Suspended ? 'AI use case suspended by monitoring review' : 'AI monitoring review requires action',
                        'description' => $data['performance_summary'],
                        'severity' => $outcome === AiMonitoringOutcome::Suspended ? 'critical' : 'high', 'status' => 'open',
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

    protected function lockGovernanceGraph(AiUseCase $useCase): void
    {
        $system = AiSystem::query()->lockForUpdate()->findOrFail($useCase->ai_system_id);
        DB::table('ai_use_case_control')->where('ai_use_case_id', $useCase->id)
            ->orderBy('control_id')->lockForUpdate()->get();
        DB::table('ai_use_case_risk')->where('ai_use_case_id', $useCase->id)
            ->orderBy('risk_id')->lockForUpdate()->get();
        $useCase->setRelation('aiSystem', $system);
        $useCase->setRelation('controls', $useCase->controls()->orderBy('controls.id')->get());
        $useCase->setRelation('risks', $useCase->risks()->orderBy('risks.id')->get());
    }
}
