<?php

namespace App\ComplianceCases;

use App\Enums\ComplianceCaseClosureReportReviewDecision;
use App\Enums\ComplianceCaseDispositionDecision;
use App\Enums\ComplianceCaseStatus;
use App\Models\ComplianceCase;
use App\Models\ComplianceCaseClosureReport;
use App\Models\ComplianceCaseClosureReportReview;
use App\Models\ComplianceCaseDispositionReview;
use App\Models\ComplianceCaseLegalHold;
use App\Models\ComplianceCaseRetentionClassification;
use App\Models\User;
use App\Support\CanonicalJson;
use App\Support\Enterprise;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ComplianceCaseRetentionManager
{
    /** @param array{policy_reference:string,classification:string,starts_on:string,ends_on:string,rationale:string} $data */
    public function classify(User $actor, ComplianceCase $case, array $data): ComplianceCaseRetentionClassification
    {
        Enterprise::assertEnabled('compliance_cases');

        return DB::transaction(function () use ($actor, $case, $data): ComplianceCaseRetentionClassification {
            $locked = ComplianceCase::query()->lockForUpdate()->findOrFail($case->id);
            abort_unless($actor->can('Manage Compliance Cases') && $actor->can('view', $locked), 403);
            app(ComplianceCaseConflictManager::class)->assertClear($actor, $locked);
            if ($locked->status !== ComplianceCaseStatus::Closed) {
                throw ValidationException::withMessages(['case' => 'Retention classification requires a closed case.']);
            }
            $package = $this->latestApprovedPackage($locked);
            $data = Validator::make($data, self::classifyRules())->validate();
            $startsOn = Carbon::parse($data['starts_on'], 'UTC')->startOfDay();
            $endsOn = Carbon::parse($data['ends_on'], 'UTC')->startOfDay();
            if ($endsOn->lt($startsOn)) {
                throw ValidationException::withMessages(['ends_on' => 'Retention cannot end before it starts.']);
            }
            $existing = ComplianceCaseRetentionClassification::query()->where('compliance_case_id', $locked->id)->orderBy('version')->lockForUpdate()->get();
            if ($existing->count() >= 20) {
                throw ValidationException::withMessages(['case' => 'A governed compliance case is limited to 20 retention classifications.']);
            }
            $classifiedAt = now()->startOfSecond();
            $record = new ComplianceCaseRetentionClassification([
                'compliance_case_id' => $locked->id, 'version' => $existing->count() + 1,
                'policy_reference' => trim($data['policy_reference']), 'classification' => trim($data['classification']),
                'starts_on' => $startsOn->toDateString(), 'ends_on' => $endsOn->toDateString(), 'rationale' => trim($data['rationale']),
                'classified_by' => $actor->id, 'classifier_snapshot' => $actor->only(['id', 'name', 'email']),
                'case_snapshot' => [
                    'id' => $locked->id, 'number' => $locked->number, 'status' => $locked->status->value,
                    'closed_at' => $locked->closed_at?->toIso8601String(),
                    'closure_package_id' => $package['id'],
                    'closure_package_fingerprint' => $package['fingerprint'],
                    'closure_package_review_fingerprint' => $package['review_fingerprint'],
                ],
                'classified_at' => $classifiedAt,
            ]);
            $record->fingerprint = hash('sha256', CanonicalJson::encode($this->payload($record)));
            $record->save();

            return $record->load('disposition');
        }, 3);
    }

    /** @param array{decision:string,summary:string} $data */
    public function review(User $actor, ComplianceCaseRetentionClassification $classification, array $data): ComplianceCaseDispositionReview
    {
        Enterprise::assertEnabled('compliance_cases');

        return DB::transaction(function () use ($actor, $classification, $data): ComplianceCaseDispositionReview {
            $case = ComplianceCase::query()->lockForUpdate()->findOrFail($classification->compliance_case_id);
            $locked = ComplianceCaseRetentionClassification::query()->where('compliance_case_id', $case->id)->lockForUpdate()->findOrFail($classification->id);
            abort_unless($actor->can('Manage Compliance Cases') && $actor->can('view', $case), 403);
            abort_if($actor->id === $locked->classified_by, 403, 'The classifier cannot review disposition.');
            app(ComplianceCaseConflictManager::class)->assertClear($actor, $case);
            $current = $this->latestApprovedPackage($case);
            if (data_get($locked->case_snapshot, 'closure_package_fingerprint') !== $current['fingerprint']) {
                throw ValidationException::withMessages(['package' => 'Disposition review requires the exact current approved closure package.']);
            }
            $unreleased = ComplianceCaseLegalHold::query()->where('compliance_case_id', $case->id)
                ->whereDoesntHave('release')->lockForUpdate()->exists();
            if ($unreleased) {
                throw ValidationException::withMessages(['holds' => 'Legal holds must be released before disposition review.']);
            }
            $data = Validator::make($data, self::reviewRules())->validate();
            if (ComplianceCaseDispositionReview::query()->where('compliance_case_retention_classification_id', $locked->id)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['classification' => 'This classification already has a disposition review.']);
            }
            $reviewedAt = now()->startOfSecond();
            $review = new ComplianceCaseDispositionReview([
                'compliance_case_retention_classification_id' => $locked->id,
                'decision' => ComplianceCaseDispositionDecision::from($data['decision']),
                'summary' => trim($data['summary']), 'reviewed_by' => $actor->id,
                'reviewer_snapshot' => $actor->only(['id', 'name', 'email']),
                'classification_snapshot' => ['id' => $locked->id, 'fingerprint' => $locked->fingerprint] + $this->payload($locked),
                'reviewed_at' => $reviewedAt,
            ]);
            $review->fingerprint = hash('sha256', CanonicalJson::encode($this->reviewPayload($review)));
            $review->save();

            return $review;
        }, 3);
    }

    public function history(User $actor, ComplianceCase $case, int $perPage): LengthAwarePaginator
    {
        Enterprise::assertEnabled('compliance_cases');
        abort_unless($actor->can('view', $case), 403);

        return $case->retentionClassifications()->with('disposition')->paginate($perPage);
    }

    /** @return array<string,mixed> */
    public function payload(ComplianceCaseRetentionClassification $record): array
    {
        return [
            'compliance_case_id' => $record->compliance_case_id, 'version' => $record->version,
            'policy_reference' => $record->policy_reference, 'classification' => $record->classification,
            'starts_on' => $record->starts_on?->toDateString(), 'ends_on' => $record->ends_on?->toDateString(),
            'rationale' => $record->rationale, 'classified_by' => $record->classified_by,
            'classifier_snapshot' => $record->classifier_snapshot, 'case_snapshot' => $record->case_snapshot,
            'classified_at' => $record->classified_at?->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> */
    public function reviewPayload(ComplianceCaseDispositionReview $review): array
    {
        return [
            'compliance_case_retention_classification_id' => $review->compliance_case_retention_classification_id,
            'decision' => $review->decision instanceof \BackedEnum ? $review->decision->value : $review->decision,
            'summary' => $review->summary, 'reviewed_by' => $review->reviewed_by,
            'reviewer_snapshot' => $review->reviewer_snapshot, 'classification_snapshot' => $review->classification_snapshot,
            'reviewed_at' => $review->reviewed_at?->toIso8601String(),
        ];
    }

    /** @return array{id:int,fingerprint:string,review_fingerprint:string} */
    private function latestApprovedPackage(ComplianceCase $case): array
    {
        $package = ComplianceCaseClosureReport::query()->where('compliance_case_id', $case->id)->orderBy('version')->lockForUpdate()->get()->last();
        $review = $package === null ? null : ComplianceCaseClosureReportReview::query()
            ->where('compliance_case_closure_report_id', $package->id)->lockForUpdate()->first();
        if ($package === null || $review?->decision !== ComplianceCaseClosureReportReviewDecision::Approved) {
            throw ValidationException::withMessages(['package' => 'Retention requires the latest independently approved closure package.']);
        }

        return ['id' => $package->id, 'fingerprint' => $package->fingerprint, 'review_fingerprint' => $review->fingerprint];
    }

    /** @return array<string,mixed> */
    public static function classifyRules(): array
    {
        return [
            'policy_reference' => 'required|string|max:255', 'classification' => 'required|string|max:100',
            'starts_on' => 'required|date', 'ends_on' => 'required|date', 'rationale' => 'required|string|max:30000',
            'id' => 'prohibited', 'version' => 'prohibited', 'fingerprint' => 'prohibited',
            'classified_by' => 'prohibited', 'classified_at' => 'prohibited', 'classifier_snapshot' => 'prohibited',
            'case_snapshot' => 'prohibited',
        ];
    }

    /** @return array<string,mixed> */
    public static function reviewRules(): array
    {
        return [
            'decision' => ['required', Rule::enum(ComplianceCaseDispositionDecision::class)],
            'summary' => 'required|string|max:30000',
            'id' => 'prohibited', 'fingerprint' => 'prohibited', 'reviewed_by' => 'prohibited',
            'reviewed_at' => 'prohibited', 'reviewer_snapshot' => 'prohibited', 'classification_snapshot' => 'prohibited',
        ];
    }
}
