<?php

namespace App\ThirdPartyRisk;

use App\Enums\SurveyStatus;
use App\Enums\SurveyType;
use App\Enums\ThirdPartyDueDiligenceDecision;
use App\Enums\ThirdPartyEngagementStatus;
use App\Enums\ThirdPartyRiskDecisionType;
use App\Enums\VendorDocumentStatus;
use App\Models\Survey;
use App\Models\SurveyAnswer;
use App\Models\SurveyAttachment;
use App\Models\SurveyQuestion;
use App\Models\SurveyTemplate;
use App\Models\ThirdPartyEngagement;
use App\Models\ThirdPartyEngagementDueDiligenceReview;
use App\Models\ThirdPartyEngagementEvent;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorDocument;
use App\Models\VendorRiskAssessment;
use App\Models\VendorRiskDecision;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ThirdPartyEngagementDueDiligenceManager
{
    public function review(User $actor, ThirdPartyEngagement $engagement, array $data): ThirdPartyEngagementDueDiligenceReview
    {
        $this->assertCanManage($actor);

        return DB::transaction(function () use ($actor, $engagement, $data): ThirdPartyEngagementDueDiligenceReview {
            $vendorId = ThirdPartyEngagement::query()->whereKey($engagement->id)->value('vendor_id');
            $vendor = Vendor::withTrashed()->lockForUpdate()->findOrFail($vendorId);
            $locked = ThirdPartyEngagement::query()->where('vendor_id', $vendor->id)->lockForUpdate()->findOrFail($engagement->id);
            $this->assertCanManage($actor);
            $data = Validator::make($data, self::rules())->validate();
            if ($locked->status !== ThirdPartyEngagementStatus::DueDiligence) {
                throw ValidationException::withMessages(['engagement' => 'Due-diligence review requires the governed due-diligence phase.']);
            }
            if ($locked->dueDiligenceReviews()->count() >= 100) {
                throw ValidationException::withMessages(['engagement' => 'An engagement is limited to 100 retained due-diligence reviews.']);
            }
            $event = ThirdPartyEngagementEvent::query()->where('third_party_engagement_id', $locked->id)->lockForUpdate()->orderByDesc('version')->firstOrFail();
            [$assessment, $decision, $riskSnapshot] = $this->currentRiskApproval($vendor);
            abort_if(in_array($actor->id, [$locked->proposed_by, $locked->business_owner_id, $assessment->assessor_id, $decision->decided_by], true), 403, 'Due-diligence review must be separated from proposal, ownership, assessment, and risk approval.');

            $survey = Survey::withTrashed()->lockForUpdate()->find($data['survey_id']);
            abort_unless($survey && $actor->can('view', $survey), 403, 'The selected survey is unavailable for due-diligence evidence.');
            if ($survey->trashed() || $survey->vendor_id !== $vendor->id || $survey->type !== SurveyType::VENDOR_ASSESSMENT || $survey->status !== SurveyStatus::COMPLETED || $survey->risk_score === null || $survey->risk_score_calculated_at === null) {
                throw ValidationException::withMessages(['survey_id' => 'Select a current completed and scored vendor assessment for this vendor.']);
            }
            $surveySnapshot = $this->surveySnapshot($survey);
            $documentSnapshots = $this->documentSnapshots($actor, $vendor, $data['vendor_document_ids'] ?? []);
            $nextReview = Carbon::parse($data['next_review_at'])->toDateString();
            if ($nextReview < today()->toDateString() || $nextReview > $locked->term_end_at->toDateString()) {
                throw ValidationException::withMessages(['next_review_at' => 'The due-diligence review date must be current and fall within the engagement term.']);
            }
            $decisionType = ThirdPartyDueDiligenceDecision::from($data['decision']);
            if ($decisionType === ThirdPartyDueDiligenceDecision::Conditional && blank($data['conditions'] ?? null)) {
                throw ValidationException::withMessages(['conditions' => 'Conditional due diligence requires explicit conditions.']);
            }
            $version = ((int) $locked->dueDiligenceReviews()->max('version')) + 1;
            $at = now()->startOfSecond();
            $payload = ['third_party_engagement_id' => $locked->id, 'version' => $version, 'survey_id' => $survey->id,
                'cybersecurity_rating' => $data['cybersecurity_rating'], 'privacy_rating' => $data['privacy_rating'], 'resilience_rating' => $data['resilience_rating'],
                'compliance_rating' => $data['compliance_rating'], 'financial_rating' => $data['financial_rating'], 'findings_summary' => $data['findings_summary'],
                'decision' => $decisionType->value, 'conditions' => $data['conditions'] ?? null, 'rationale' => $data['rationale'], 'next_review_at' => $nextReview,
                'engagement_snapshot' => $this->engagementSnapshot($locked), 'survey_snapshot' => $surveySnapshot, 'document_snapshots' => $documentSnapshots,
                'risk_approval_snapshot' => $riskSnapshot, 'engagement_event_fingerprint' => $event->fingerprint, 'reviewed_by' => $actor->id, 'reviewed_at' => $at->toIso8601String()];

            return ThirdPartyEngagementDueDiligenceReview::query()->create($payload + ['fingerprint' => $this->fingerprint($payload)])->load('reviewer:id,name');
        }, 3);
    }

    public static function rules(): array
    {
        return ['survey_id' => 'required|integer', 'vendor_document_ids' => 'sometimes|array|max:20', 'vendor_document_ids.*' => 'integer|distinct',
            'cybersecurity_rating' => 'required|integer|min:1|max:5', 'privacy_rating' => 'required|integer|min:1|max:5', 'resilience_rating' => 'required|integer|min:1|max:5',
            'compliance_rating' => 'required|integer|min:1|max:5', 'financial_rating' => 'required|integer|min:1|max:5', 'findings_summary' => 'required|string|max:30000',
            'decision' => ['required', Rule::enum(ThirdPartyDueDiligenceDecision::class)], 'conditions' => 'nullable|string|max:30000', 'rationale' => 'required|string|max:30000', 'next_review_at' => 'required|date_format:Y-m-d',
            'version' => 'prohibited', 'engagement_snapshot' => 'prohibited', 'survey_snapshot' => 'prohibited', 'document_snapshots' => 'prohibited', 'risk_approval_snapshot' => 'prohibited',
            'engagement_event_fingerprint' => 'prohibited', 'reviewed_by' => 'prohibited', 'reviewed_at' => 'prohibited', 'fingerprint' => 'prohibited'];
    }

    /**
     * @param  array<string, mixed>  $riskApprovalSnapshot
     */
    public function currentAcceptedReview(ThirdPartyEngagement $engagement, array $riskApprovalSnapshot, User $approver): ThirdPartyEngagementDueDiligenceReview
    {
        $event = ThirdPartyEngagementEvent::query()->where('third_party_engagement_id', $engagement->id)->lockForUpdate()->orderByDesc('version')->firstOrFail();
        $review = ThirdPartyEngagementDueDiligenceReview::query()->where('third_party_engagement_id', $engagement->id)->lockForUpdate()->orderByDesc('version')->first();
        abort_if($review?->reviewed_by === $approver->id, 403, 'Engagement approval must be separated from the due-diligence reviewer.');
        if (! $review
            || ! in_array($review->decision, [ThirdPartyDueDiligenceDecision::Satisfactory, ThirdPartyDueDiligenceDecision::Conditional], true)
            || $review->engagement_event_fingerprint !== $event->fingerprint
            || data_get($review->risk_approval_snapshot, 'decision.id') !== data_get($riskApprovalSnapshot, 'decision.id')
            || data_get($review->risk_approval_snapshot, 'governance.fingerprint') !== data_get($riskApprovalSnapshot, 'governance.fingerprint')
            || $review->next_review_at->copy()->endOfDay()->isPast()
            || $review->next_review_at->toDateString() > $engagement->term_end_at->toDateString()) {
            throw ValidationException::withMessages(['due_diligence_review' => 'A current accepted structured due-diligence review is required.']);
        }

        return $review;
    }

    public function approvalBinding(ThirdPartyEngagementDueDiligenceReview $review): array
    {
        return Arr::only($review->toArray(), ['id', 'version', 'decision', 'conditions', 'next_review_at', 'reviewed_by', 'reviewed_at', 'fingerprint']);
    }

    public function visibleReview(ThirdPartyEngagementDueDiligenceReview $review, User $actor): ThirdPartyEngagementDueDiligenceReview
    {
        return $this->visibleReviews(collect([$review]), $actor)->firstOrFail();
    }

    /** @param Collection<int, ThirdPartyEngagementDueDiligenceReview> $reviews
     * @return Collection<int, ThirdPartyEngagementDueDiligenceReview>
     */
    public function visibleReviews(Collection $reviews, User $actor): Collection
    {
        $surveys = Survey::withTrashed()->whereIn('id', $reviews->pluck('survey_id')->unique())->get()->keyBy('id');
        $documentIds = $reviews->flatMap(fn (ThirdPartyEngagementDueDiligenceReview $review) => collect($review->document_snapshots ?? [])->pluck('id'))->filter()->unique();
        $documents = VendorDocument::withTrashed()->whereIn('id', $documentIds)->get()->keyBy('id');

        return $reviews->map(function (ThirdPartyEngagementDueDiligenceReview $review) use ($actor, $surveys, $documents): ThirdPartyEngagementDueDiligenceReview {
            $visible = clone $review;
            $survey = $surveys->get($review->survey_id);
            if (! $survey || ! $actor->can('view', $survey)) {
                $visible->setAttribute('survey_snapshot', null);
            }
            $authorizedDocuments = collect($review->document_snapshots ?? [])->filter(function (array $snapshot) use ($actor, $documents): bool {
                $document = $documents->get($snapshot['id'] ?? null);

                return $document !== null && $actor->can('view', $document);
            })->values()->all();
            $visible->setAttribute('document_snapshots', $authorizedDocuments);

            return $visible;
        });
    }

    /** @return array{VendorRiskAssessment, VendorRiskDecision, array<string, mixed>} */
    private function currentRiskApproval(Vendor $vendor): array
    {
        $assessment = VendorRiskAssessment::query()->where('vendor_id', $vendor->id)->lockForUpdate()->latest('version')->first();
        $decision = VendorRiskDecision::query()->where('vendor_id', $vendor->id)->lockForUpdate()->orderByDesc('id')->first();
        $risks = $vendor->risks()->orderBy('risks.id')->lockForUpdate()->get();
        $vendor->setRelation('risks', $risks);
        $snapshot = $assessment ? $vendor->thirdPartyRiskSnapshot($assessment) : null;
        if (! $assessment || ! $decision || ! in_array($decision->decision, [ThirdPartyRiskDecisionType::Approved, ThirdPartyRiskDecisionType::ConditionallyApproved], true)
            || $decision->vendor_risk_assessment_id !== $assessment->id || $decision->expires_at?->copy()->endOfDay()->isPast()
            || $decision->governance_fingerprint !== data_get($snapshot, 'fingerprint')) {
            throw ValidationException::withMessages(['approval' => 'A current exact vendor-risk approval is required for due diligence.']);
        }

        return [$assessment, $decision, ['assessment' => $assessment->toArray(), 'decision' => $decision->toArray(), 'governance' => $snapshot]];
    }

    private function surveySnapshot(Survey $survey): array
    {
        $template = SurveyTemplate::query()->lockForUpdate()->findOrFail($survey->survey_template_id);
        $questions = SurveyQuestion::query()->where('survey_template_id', $template->id)->orderBy('id')->lockForUpdate()->get();
        $answers = SurveyAnswer::query()->where('survey_id', $survey->id)->orderBy('id')->lockForUpdate()->get();
        if ($questions->count() > 500 || $answers->count() > 500) {
            throw ValidationException::withMessages(['survey_id' => 'Due-diligence survey evidence is limited to 500 questions and answers.']);
        }
        $attachments = SurveyAttachment::query()->whereIn('survey_answer_id', $answers->pluck('id'))->orderBy('id')->lockForUpdate()->get();
        if ($attachments->count() > 100) {
            throw ValidationException::withMessages(['survey_id' => 'Due-diligence survey evidence is limited to 100 attachment metadata records.']);
        }
        $snapshot = ['survey' => Arr::only($survey->toArray(), ['id', 'survey_template_id', 'title', 'description', 'status', 'type', 'respondent_email', 'respondent_name', 'assigned_to_id', 'approver_id', 'vendor_id', 'due_date', 'expiration_date', 'completed_at', 'created_by_id', 'risk_score', 'risk_score_calculated_at']),
            'template' => Arr::only($template->toArray(), ['id', 'title', 'description', 'type', 'status']),
            'questions' => $questions->map(fn (SurveyQuestion $question) => Arr::only($question->toArray(), ['id', 'question_text', 'question_type', 'options', 'is_required', 'sort_order', 'help_text', 'allow_comments', 'risk_weight', 'risk_impact', 'option_scores']))->all(),
            'answers' => $answers->map(fn (SurveyAnswer $answer) => Arr::only($answer->toArray(), ['id', 'survey_question_id', 'answer_value', 'comment', 'manual_score', 'scored_by', 'scored_at']))->all(),
            'attachments' => $attachments->map(fn (SurveyAttachment $attachment) => Arr::only($attachment->toArray(), ['id', 'survey_answer_id', 'file_name', 'file_size', 'uploaded_by']))->all()];
        if (strlen(json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) > 5_000_000) {
            throw ValidationException::withMessages(['survey_id' => 'Serialized due-diligence survey evidence is limited to 5,000,000 bytes.']);
        }

        return $snapshot;
    }

    private function documentSnapshots(User $actor, Vendor $vendor, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $documents = VendorDocument::withTrashed()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        abort_unless($documents->count() === count($ids), 403, 'One or more selected documents are unavailable for due-diligence evidence.');
        foreach ($documents as $document) {
            abort_unless($actor->can('view', $document), 403, 'One or more selected documents are unavailable for due-diligence evidence.');
            if ($document->trashed() || $document->vendor_id !== $vendor->id || $document->status !== VendorDocumentStatus::APPROVED || $document->expiration_date?->copy()->endOfDay()->isPast()) {
                throw ValidationException::withMessages(['vendor_document_ids' => 'Every selected document must be current, approved, and belong to the engagement vendor.']);
            }
        }

        return $documents->map(fn (VendorDocument $document) => Arr::only($document->toArray(), ['id', 'vendor_id', 'document_type', 'name', 'description', 'file_name', 'file_size', 'mime_type', 'status', 'issue_date', 'expiration_date', 'review_notes', 'reviewed_by', 'reviewed_at']))->all();
    }

    private function engagementSnapshot(ThirdPartyEngagement $engagement): array
    {
        $engagement->load(['businessOwner:id,name,email', 'proposer:id,name,email', 'approver:id,name,email']);

        return Arr::only($engagement->toArray(), ['id', 'vendor_id', 'code', 'name', 'service_description', 'business_owner_id', 'criticality', 'data_access', 'status', 'term_start_at', 'term_end_at', 'next_review_at', 'vendor_snapshot', 'approval_snapshot', 'governed_at', 'business_owner', 'proposer', 'approver']);
    }

    private function assertCanManage(User $actor): void
    {
        abort_unless($actor->isSuperAdmin() || $actor->can('Manage Third Party Risk'), 403);
    }

    private function fingerprint(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
