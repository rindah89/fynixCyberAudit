<?php

namespace Database\Factories;

use App\Enums\RiskDomain;
use App\Enums\SurveyStatus;
use App\Enums\SurveyType;
use App\Enums\ThirdPartyDueDiligenceDecision;
use App\Enums\ThirdPartyRiskDecisionType;
use App\Enums\VendorStatus;
use App\Models\Risk;
use App\Models\Survey;
use App\Models\ThirdPartyEngagement;
use App\Models\ThirdPartyEngagementDueDiligenceReview;
use App\Models\User;
use App\Models\Vendor;
use App\ThirdPartyRisk\ThirdPartyEngagementManager;
use App\ThirdPartyRisk\ThirdPartyRiskManager;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Arr;

class ThirdPartyEngagementDueDiligenceReviewFactory extends Factory
{
    protected $model = ThirdPartyEngagementDueDiligenceReview::class;

    /** @var array{engagement: ThirdPartyEngagement, survey: Survey, reviewer: User}|null */
    private ?array $context = null;

    public function definition(): array
    {
        return [
            'third_party_engagement_id' => fn (): int => $this->context()['engagement']->id,
            'version' => 1,
            'survey_id' => fn (): int => $this->context()['survey']->id,
            'cybersecurity_rating' => 3,
            'privacy_rating' => 3,
            'resilience_rating' => 3,
            'compliance_rating' => 3,
            'financial_rating' => 3,
            'findings_summary' => 'Factory due-diligence findings.',
            'decision' => ThirdPartyDueDiligenceDecision::Satisfactory,
            'conditions' => null,
            'rationale' => 'Factory survey and risk context support progression.',
            'next_review_at' => today()->addMonths(6),
            'engagement_snapshot' => [],
            'survey_snapshot' => [],
            'document_snapshots' => [],
            'risk_approval_snapshot' => [],
            'engagement_event_fingerprint' => str_repeat('0', 64),
            'reviewed_by' => fn (): int => $this->context()['reviewer']->id,
            'reviewed_at' => now()->startOfSecond(),
            'fingerprint' => str_repeat('0', 64),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ThirdPartyEngagementDueDiligenceReview $review): void {
            $engagement = ThirdPartyEngagement::query()->findOrFail($review->third_party_engagement_id);
            $engagement->load(['businessOwner:id,name,email', 'proposer:id,name,email', 'approver:id,name,email']);
            $survey = Survey::query()->with('template')->findOrFail($review->survey_id);
            $vendor = Vendor::withTrashed()->findOrFail($engagement->vendor_id);
            $assessment = $vendor->riskAssessments()->orderByDesc('version')->firstOrFail();
            $decision = $vendor->riskDecisions()->orderByDesc('id')->firstOrFail();
            $vendor->setRelation('risks', $vendor->risks()->orderBy('risks.id')->get());
            $at = now()->startOfSecond();
            $payload = [
                'third_party_engagement_id' => $engagement->id,
                'version' => $review->version,
                'survey_id' => $survey->id,
                'cybersecurity_rating' => $review->cybersecurity_rating,
                'privacy_rating' => $review->privacy_rating,
                'resilience_rating' => $review->resilience_rating,
                'compliance_rating' => $review->compliance_rating,
                'financial_rating' => $review->financial_rating,
                'findings_summary' => $review->findings_summary,
                'decision' => $review->decision->value,
                'conditions' => $review->conditions,
                'rationale' => $review->rationale,
                'next_review_at' => $review->next_review_at->toDateString(),
                'engagement_snapshot' => Arr::only($engagement->toArray(), ['id', 'vendor_id', 'code', 'name', 'service_description', 'business_owner_id', 'criticality', 'data_access', 'status', 'term_start_at', 'term_end_at', 'next_review_at', 'vendor_snapshot', 'approval_snapshot', 'governed_at', 'business_owner', 'proposer', 'approver']),
                'survey_snapshot' => [
                    'survey' => Arr::only($survey->toArray(), ['id', 'survey_template_id', 'title', 'description', 'status', 'type', 'respondent_email', 'respondent_name', 'assigned_to_id', 'approver_id', 'vendor_id', 'due_date', 'expiration_date', 'completed_at', 'created_by_id', 'risk_score', 'risk_score_calculated_at']),
                    'template' => Arr::only($survey->template->toArray(), ['id', 'title', 'description', 'type', 'status']),
                    'questions' => [], 'answers' => [], 'attachments' => [],
                ],
                'document_snapshots' => [],
                'risk_approval_snapshot' => ['assessment' => $assessment->toArray(), 'decision' => $decision->toArray(), 'governance' => $vendor->thirdPartyRiskSnapshot($assessment)],
                'engagement_event_fingerprint' => $engagement->events()->reorder()->orderByDesc('version')->value('fingerprint'),
                'reviewed_by' => $review->reviewed_by,
                'reviewed_at' => $at->toIso8601String(),
            ];
            $review->fill($payload + ['fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))]);
        });
    }

    /** @return array{engagement: ThirdPartyEngagement, survey: Survey, reviewer: User} */
    private function context(): array
    {
        if ($this->context !== null) {
            return $this->context;
        }
        $actors = collect(range(1, 5))->map(fn () => tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo(['Manage Third Party Risk', 'Read Surveys'])));
        [$proposer, $assessor, $decider, $reviewer] = $actors->all();
        $owner = User::factory()->create();
        $vendor = Vendor::factory()->create(['status' => VendorStatus::ACCEPTED]);
        $risk = app(ThirdPartyRiskManager::class);
        $risk->assess($vendor, $assessor, ['likelihood' => 3, 'impact' => 4, 'residual_likelihood' => 2, 'residual_impact' => 2, 'risk_categories' => ['cybersecurity'], 'assessment_summary' => 'Factory assessment.', 'treatment_summary' => 'Factory treatment.']);
        $risk->mapRisk($vendor, Risk::factory()->create(['domain' => RiskDomain::ThirdParty]));
        $risk->decide($vendor, $decider, ThirdPartyRiskDecisionType::Approved, ['rationale' => 'Factory approval.', 'expires_at' => today()->addYear(), 'next_review_at' => today()->addMonths(6)]);
        $manager = app(ThirdPartyEngagementManager::class);
        $engagement = $manager->propose($proposer, $vendor, ['code' => fake()->unique()->bothify('ENG-DD-####'), 'name' => 'Factory due-diligence engagement', 'service_description' => 'Factory service.', 'business_owner_id' => $owner->id, 'criticality' => 'high', 'data_access' => true, 'term_start_at' => today(), 'term_end_at' => today()->addYear(), 'next_review_at' => today()->addMonths(6)]);
        $manager->transition($proposer, $engagement, ['status' => 'due_diligence', 'summary' => 'Factory due diligence.']);
        $survey = Survey::factory()->create(['vendor_id' => $vendor->id, 'type' => SurveyType::VENDOR_ASSESSMENT, 'status' => SurveyStatus::COMPLETED, 'risk_score' => 70, 'risk_score_calculated_at' => now()->startOfSecond(), 'completed_at' => now()->subDay()]);

        return $this->context = compact('engagement', 'survey', 'reviewer');
    }
}
