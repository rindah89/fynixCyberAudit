<?php

namespace App\ComplianceCases;

use App\Enums\ComplianceCaseClosureReportReviewDecision;
use App\Enums\ComplianceCaseStatus;
use App\Models\ComplianceCase;
use App\Models\ComplianceCaseClosureReport;
use App\Models\ComplianceCaseClosureReportReview;
use App\Models\ComplianceCaseEvent;
use App\Models\ComplianceCaseReopenProposal;
use App\Models\ComplianceCaseReopenReview;
use App\Models\ComplianceCaseRetentionClassification;
use App\Models\User;
use App\Support\CanonicalJson;
use App\Support\Enterprise;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ComplianceCaseReopenManager
{
    /** @param array{summary:string} $data */
    public function propose(User $actor, ComplianceCase $case, array $data): ComplianceCaseReopenProposal
    {
        Enterprise::assertEnabled('compliance_cases');

        return DB::transaction(function () use ($actor, $case, $data): ComplianceCaseReopenProposal {
            $locked = ComplianceCase::query()->lockForUpdate()->findOrFail($case->id);
            abort_unless($actor->can('Manage Compliance Cases') && $actor->can('view', $locked), 403);
            app(ComplianceCaseConflictManager::class)->assertClear($actor, $locked);
            if ($locked->status !== ComplianceCaseStatus::Closed) {
                throw ValidationException::withMessages(['case' => 'Only a closed case can be proposed for reopen.']);
            }
            $package = ComplianceCaseClosureReport::query()->where('compliance_case_id', $locked->id)->orderBy('version')->lockForUpdate()->get()->last();
            $packageReview = $package === null ? null : ComplianceCaseClosureReportReview::query()
                ->where('compliance_case_closure_report_id', $package->id)->lockForUpdate()->first();
            if ($package === null || $packageReview?->decision !== ComplianceCaseClosureReportReviewDecision::Approved) {
                throw ValidationException::withMessages(['package' => 'Reopen requires the latest independently approved closure package.']);
            }
            $retention = ComplianceCaseRetentionClassification::query()->where('compliance_case_id', $locked->id)->orderBy('version')->lockForUpdate()->get()->last();
            $data = Validator::make($data, ['summary' => 'required|string|max:30000'])->validate();
            $existing = ComplianceCaseReopenProposal::query()->where('compliance_case_id', $locked->id)->orderBy('version')->lockForUpdate()->get();
            if ($existing->count() >= 20) {
                throw ValidationException::withMessages(['case' => 'A governed compliance case is limited to 20 reopen proposals.']);
            }
            $latest = $existing->last();
            if ($latest && $latest->review()->doesntExist()) {
                throw ValidationException::withMessages(['case' => 'The latest reopen proposal must be reviewed before a replacement is submitted.']);
            }
            $proposedAt = now()->startOfSecond();
            $proposal = new ComplianceCaseReopenProposal([
                'compliance_case_id' => $locked->id, 'version' => $existing->count() + 1,
                'summary' => trim($data['summary']), 'proposed_by' => $actor->id,
                'proposer_snapshot' => $actor->only(['id', 'name', 'email']),
                'case_snapshot' => [
                    'id' => $locked->id, 'number' => $locked->number, 'status' => $locked->status->value,
                    'closure_summary' => $locked->closure_summary, 'closed_at' => $locked->closed_at?->toIso8601String(),
                    'closure_package_id' => $package->id, 'closure_package_fingerprint' => $package->fingerprint,
                    'closure_package_review_fingerprint' => $packageReview->fingerprint,
                    'retention_classification_id' => $retention?->id,
                    'retention_fingerprint' => $retention?->fingerprint,
                ],
                'proposed_at' => $proposedAt,
            ]);
            $proposal->fingerprint = hash('sha256', CanonicalJson::encode($this->proposalPayload($proposal)));
            $proposal->save();

            return $proposal->load('review');
        }, 3);
    }

    /** @param array{decision:string,summary:string} $data */
    public function review(User $actor, ComplianceCaseReopenProposal $proposal, array $data): ComplianceCaseReopenReview
    {
        Enterprise::assertEnabled('compliance_cases');

        return DB::transaction(function () use ($actor, $proposal, $data): ComplianceCaseReopenReview {
            $case = ComplianceCase::query()->lockForUpdate()->findOrFail($proposal->compliance_case_id);
            $locked = ComplianceCaseReopenProposal::query()->where('compliance_case_id', $case->id)->lockForUpdate()->findOrFail($proposal->id);
            abort_unless($actor->can('Manage Compliance Cases') && $actor->can('view', $case), 403);
            abort_if($actor->id === $locked->proposed_by, 403, 'The proposer cannot review the reopen proposal.');
            app(ComplianceCaseConflictManager::class)->assertClear($actor, $case);
            $data = Validator::make($data, [
                'decision' => 'required|in:approved,rejected', 'summary' => 'required|string|max:30000',
            ])->validate();
            $latest = ComplianceCaseReopenProposal::query()->where('compliance_case_id', $case->id)->orderByDesc('version')->lockForUpdate()->firstOrFail();
            if ($latest->id !== $locked->id) {
                throw ValidationException::withMessages(['proposal' => 'Only the latest reopen proposal can be reviewed.']);
            }
            if ($locked->review()->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['proposal' => 'This proposal already has a terminal review.']);
            }
            $package = ComplianceCaseClosureReport::query()->where('compliance_case_id', $case->id)->orderBy('version')->lockForUpdate()->get()->last();
            if ($package === null || data_get($locked->case_snapshot, 'closure_package_fingerprint') !== $package->fingerprint) {
                throw ValidationException::withMessages(['package' => 'Reopen review requires the exact current approved closure package.']);
            }
            $retention = ComplianceCaseRetentionClassification::query()
                ->where('compliance_case_id', $case->id)->orderByDesc('version')->lockForUpdate()->first();
            if (data_get($locked->case_snapshot, 'retention_classification_id') !== $retention?->id
                || data_get($locked->case_snapshot, 'retention_fingerprint') !== $retention?->fingerprint) {
                throw ValidationException::withMessages(['retention' => 'Reopen review requires the exact current retention context.']);
            }
            $reviewedAt = now()->startOfSecond();
            $review = new ComplianceCaseReopenReview([
                'compliance_case_reopen_proposal_id' => $locked->id, 'decision' => $data['decision'],
                'summary' => trim($data['summary']), 'reviewed_by' => $actor->id,
                'reviewer_snapshot' => $actor->only(['id', 'name', 'email']),
                'proposal_snapshot' => ['id' => $locked->id, 'fingerprint' => $locked->fingerprint] + $this->proposalPayload($locked),
                'reviewed_at' => $reviewedAt,
            ]);
            $review->fingerprint = hash('sha256', CanonicalJson::encode($this->reviewPayload($review)));
            $review->save();
            if ($data['decision'] === 'approved') {
                $this->startCycle($case, $actor, $locked);
            }

            return $review;
        }, 3);
    }

    public function history(User $actor, ComplianceCase $case, int $perPage): LengthAwarePaginator
    {
        Enterprise::assertEnabled('compliance_cases');
        abort_unless($actor->can('view', $case), 403);

        return $case->reopenProposals()->with('review')->paginate($perPage);
    }

    /** @return array<string,mixed> */
    public function proposalPayload(ComplianceCaseReopenProposal $proposal): array
    {
        return [
            'compliance_case_id' => $proposal->compliance_case_id, 'version' => $proposal->version,
            'summary' => $proposal->summary, 'proposed_by' => $proposal->proposed_by,
            'proposer_snapshot' => $proposal->proposer_snapshot, 'case_snapshot' => $proposal->case_snapshot,
            'proposed_at' => $proposal->proposed_at?->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> */
    public function reviewPayload(ComplianceCaseReopenReview $review): array
    {
        return [
            'compliance_case_reopen_proposal_id' => $review->compliance_case_reopen_proposal_id,
            'decision' => $review->decision, 'summary' => $review->summary, 'reviewed_by' => $review->reviewed_by,
            'reviewer_snapshot' => $review->reviewer_snapshot, 'proposal_snapshot' => $review->proposal_snapshot,
            'reviewed_at' => $review->reviewed_at?->toIso8601String(),
        ];
    }

    private function startCycle(ComplianceCase $case, User $actor, ComplianceCaseReopenProposal $proposal): void
    {
        $events = ComplianceCaseEvent::query()->where('compliance_case_id', $case->id)->orderBy('version')->lockForUpdate()->get();
        if ($events->count() >= 200) {
            throw ValidationException::withMessages(['case' => 'A governed compliance case is limited to 200 events.']);
        }
        $version = ((int) ($events->last()?->version ?? 0)) + 1;
        $before = app(ComplianceCaseManager::class)->snapshotForReopen($case);
        $case->status = ComplianceCaseStatus::Investigating;
        $case->save();
        $after = app(ComplianceCaseManager::class)->snapshotForReopen($case->refresh());
        $recordedAt = now()->startOfSecond();
        $payload = [
            'compliance_case_id' => $case->id, 'version' => $version, 'event_type' => 'reopened',
            'before_snapshot' => $before, 'after_snapshot' => $after,
            'summary' => 'A new investigation cycle started from approved reopen proposal '.$proposal->id.'.',
            'recorded_by' => $actor->id, 'recorded_at' => $recordedAt->toIso8601String(),
        ];
        ComplianceCaseEvent::query()->create($payload + [
            'fingerprint' => hash('sha256', CanonicalJson::encode($payload)),
        ]);
    }
}
