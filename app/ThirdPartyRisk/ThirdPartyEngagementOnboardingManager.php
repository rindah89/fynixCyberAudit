<?php

namespace App\ThirdPartyRisk;

use App\Enums\ThirdPartyEngagementStatus;
use App\Enums\ThirdPartyOnboardingCategory;
use App\Enums\ThirdPartyOnboardingDecision;
use App\Models\ThirdPartyContractRiskReview;
use App\Models\ThirdPartyEngagement;
use App\Models\ThirdPartyEngagementEvent;
use App\Models\ThirdPartyEngagementOnboardingCompletion;
use App\Models\ThirdPartyEngagementOnboardingReadinessReview;
use App\Models\ThirdPartyEngagementOnboardingRequirement;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ThirdPartyEngagementOnboardingManager
{
    public function define(User $actor, ThirdPartyEngagement $engagement, array $data): ThirdPartyEngagementOnboardingRequirement
    {
        $this->assertManager($actor);

        return DB::transaction(function () use ($actor, $engagement, $data) {
            [$vendor,$locked] = $this->lockEngagement($engagement);
            $this->assertManager($actor);
            $data = Validator::make($data, self::definitionRules())->validate();
            if ($locked->status !== ThirdPartyEngagementStatus::Approved) {
                throw ValidationException::withMessages(['engagement' => 'Onboarding requirements can be defined only for an approved engagement.']);
            }
            app(ThirdPartyEngagementManager::class)->currentAcceptedContractReview($locked, $locked->term_end_at->toDateString());
            if ($locked->onboardingRequirements()->count() >= 100) {
                throw ValidationException::withMessages(['engagement' => 'An engagement is limited to 100 onboarding requirements.']);
            }
            $owner = User::query()->whereNull('deleted_at')->lockForUpdate()->findOrFail($data['owner_id']);
            $due = Carbon::parse($data['due_at'])->toDateString();
            if ($due < today()->toDateString() || $due > $locked->term_end_at->toDateString()) {
                throw ValidationException::withMessages(['due_at' => 'The onboarding due date must be current and within the engagement term.']);
            }
            $at = now()->startOfSecond();
            $payload = ['third_party_engagement_id' => $locked->id, 'version' => ((int) $locked->onboardingRequirements()->max('version')) + 1, 'category' => ThirdPartyOnboardingCategory::from($data['category'])->value, 'title' => $data['title'], 'acceptance_criteria' => $data['acceptance_criteria'], 'owner_id' => $owner->id, 'due_at' => $due, 'required' => $data['required'], 'engagement_snapshot' => $this->engagementSnapshot($locked), 'defined_by' => $actor->id, 'defined_at' => $at->toIso8601String()];

            return ThirdPartyEngagementOnboardingRequirement::query()->create($payload + ['fingerprint' => $this->fingerprint($payload)])->load(['owner:id,name', 'definer:id,name']);
        }, 3);
    }

    public function complete(User $actor, ThirdPartyEngagementOnboardingRequirement $requirement, array $data): ThirdPartyEngagementOnboardingCompletion
    {
        return DB::transaction(function () use ($actor, $requirement, $data) {
            $engagementId = ThirdPartyEngagementOnboardingRequirement::query()->whereKey($requirement->id)->value('third_party_engagement_id');
            $vendorId = ThirdPartyEngagement::query()->whereKey($engagementId)->value('vendor_id');
            Vendor::withTrashed()->lockForUpdate()->findOrFail($vendorId);
            $engagement = ThirdPartyEngagement::query()->lockForUpdate()->findOrFail($engagementId);
            $locked = ThirdPartyEngagementOnboardingRequirement::query()->where('third_party_engagement_id', $engagement->id)->lockForUpdate()->findOrFail($requirement->id);
            abort_unless($actor->isSuperAdmin() || $actor->can('Manage Third Party Risk') || $actor->id === $locked->owner_id, 403);
            $data = Validator::make($data, self::completionRules())->validate();
            if ($engagement->status !== ThirdPartyEngagementStatus::Approved) {
                throw ValidationException::withMessages(['engagement' => 'Onboarding completion is available only before activation.']);
            }
            app(ThirdPartyEngagementManager::class)->currentAcceptedContractReview($engagement, $engagement->term_end_at->toDateString());
            if ($locked->completions()->count() >= 10) {
                throw ValidationException::withMessages(['requirement' => 'A requirement is limited to 10 completion versions.']);
            }
            $at = now()->startOfSecond();
            $payload = ['third_party_engagement_onboarding_requirement_id' => $locked->id, 'version' => ((int) $locked->completions()->max('version')) + 1, 'completion_summary' => $data['completion_summary'], 'source_reference' => $data['source_reference'] ?? null, 'requirement_snapshot' => $locked->toArray(), 'completed_by' => $actor->id, 'completed_at' => $at->toIso8601String()];

            return ThirdPartyEngagementOnboardingCompletion::query()->create($payload + ['fingerprint' => $this->fingerprint($payload)])->load('completer:id,name');
        }, 3);
    }

    public function review(User $actor, ThirdPartyEngagement $engagement, array $data): ThirdPartyEngagementOnboardingReadinessReview
    {
        $this->assertManager($actor);

        return DB::transaction(function () use ($actor, $engagement, $data) {
            [, $locked] = $this->lockEngagement($engagement);
            $this->assertManager($actor);
            $data = Validator::make($data, self::reviewRules())->validate();
            if ($locked->status !== ThirdPartyEngagementStatus::Approved) {
                throw ValidationException::withMessages(['engagement' => 'Readiness review requires an approved engagement.']);
            }
            if ($locked->onboardingReadinessReviews()->count() >= 100) {
                throw ValidationException::withMessages(['engagement' => 'An engagement is limited to 100 readiness reviews.']);
            }
            [$snapshots, $requirementActors] = $this->currentRequirementContext($locked);
            $excluded = [$locked->proposed_by, $locked->business_owner_id, $locked->approved_by, ...$requirementActors];
            $contract = app(ThirdPartyEngagementManager::class)->currentAcceptedContractReview($locked, $locked->term_end_at->toDateString());
            $excluded[] = $contract->reviewed_by;
            abort_if(in_array($actor->id, array_filter($excluded), true), 403, 'Readiness review must be independent from engagement, contract, requirement, and completion actors.');
            $decision = ThirdPartyOnboardingDecision::from($data['decision']);
            if ($decision === ThirdPartyOnboardingDecision::ReadyWithConditions && blank($data['conditions'] ?? null)) {
                throw ValidationException::withMessages(['conditions' => 'Conditional readiness requires explicit conditions.']);
            }
            $next = Carbon::parse($data['next_review_at'])->toDateString();
            if ($next < today()->toDateString() || $next > $locked->term_end_at->toDateString()) {
                throw ValidationException::withMessages(['next_review_at' => 'The next review must be current and within the engagement term.']);
            }
            $event = ThirdPartyEngagementEvent::query()->where('third_party_engagement_id', $locked->id)->orderByDesc('version')->lockForUpdate()->firstOrFail();
            $at = now()->startOfSecond();
            $payload = ['third_party_engagement_id' => $locked->id, 'version' => ((int) $locked->onboardingReadinessReviews()->max('version')) + 1, 'decision' => $decision->value, 'conditions' => $data['conditions'] ?? null, 'summary' => $data['summary'], 'next_review_at' => $next, 'engagement_snapshot' => $this->engagementSnapshot($locked), 'requirements_snapshot' => $snapshots, 'engagement_event_fingerprint' => $event->fingerprint, 'contract_review_fingerprint' => $contract->fingerprint, 'reviewed_by' => $actor->id, 'reviewed_at' => $at->toIso8601String()];

            return ThirdPartyEngagementOnboardingReadinessReview::query()->create($payload + ['fingerprint' => $this->fingerprint($payload)])->load('reviewer:id,name');
        }, 3);
    }

    public function currentAcceptedReview(ThirdPartyEngagement $engagement, User $activator): ThirdPartyEngagementOnboardingReadinessReview
    {
        $review = ThirdPartyEngagementOnboardingReadinessReview::query()->where('third_party_engagement_id', $engagement->id)->orderByDesc('version')->lockForUpdate()->first();
        abort_if($review?->reviewed_by === $activator->id, 403, 'Activation must be separated from the readiness reviewer.');
        $event = ThirdPartyEngagementEvent::query()->where('third_party_engagement_id', $engagement->id)->orderByDesc('version')->lockForUpdate()->firstOrFail();
        $contract = ThirdPartyContractRiskReview::query()->where('third_party_engagement_id', $engagement->id)->orderByDesc('version')->lockForUpdate()->first();
        [$requirements] = $this->currentRequirementContext($engagement);
        if (! $review || ! in_array($review->decision, [ThirdPartyOnboardingDecision::Ready, ThirdPartyOnboardingDecision::ReadyWithConditions], true) || $review->requirements_snapshot !== $requirements || $review->engagement_event_fingerprint !== $event->fingerprint || $review->contract_review_fingerprint !== $contract?->fingerprint || $review->next_review_at->copy()->endOfDay()->isPast()) {
            throw ValidationException::withMessages(['onboarding_readiness' => 'A current accepted independent onboarding-readiness review is required.']);
        }

        return $review;
    }

    public static function definitionRules(): array
    {
        return ['category' => ['required', Rule::enum(ThirdPartyOnboardingCategory::class)], 'title' => 'required|string|max:255', 'acceptance_criteria' => 'required|string|max:30000', 'owner_id' => 'required|integer|exists:users,id', 'due_at' => 'required|date_format:Y-m-d', 'required' => 'required|boolean', 'version' => 'prohibited', 'fingerprint' => 'prohibited'];
    }

    public static function completionRules(): array
    {
        return ['completion_summary' => 'required|string|max:30000', 'source_reference' => 'nullable|string|max:255', 'version' => 'prohibited', 'completed_by' => 'prohibited', 'fingerprint' => 'prohibited'];
    }

    public static function reviewRules(): array
    {
        return ['decision' => ['required', Rule::enum(ThirdPartyOnboardingDecision::class)], 'conditions' => 'nullable|string|max:30000', 'summary' => 'required|string|max:30000', 'next_review_at' => 'required|date_format:Y-m-d', 'version' => 'prohibited', 'requirements_snapshot' => 'prohibited', 'reviewed_by' => 'prohibited', 'fingerprint' => 'prohibited'];
    }

    private function lockEngagement(ThirdPartyEngagement $engagement): array
    {
        $vendorId = ThirdPartyEngagement::query()->whereKey($engagement->id)->value('vendor_id');
        $vendor = Vendor::withTrashed()->lockForUpdate()->findOrFail($vendorId);

        return [$vendor, ThirdPartyEngagement::query()->where('vendor_id', $vendor->id)->lockForUpdate()->findOrFail($engagement->id)];
    }

    /** @return array{array<int, array<string, mixed>>, array<int, int>} */
    private function currentRequirementContext(ThirdPartyEngagement $engagement): array
    {
        $requirements = ThirdPartyEngagementOnboardingRequirement::query()->where('third_party_engagement_id', $engagement->id)->orderBy('id')->lockForUpdate()->get();
        if ($requirements->isEmpty()) {
            throw ValidationException::withMessages(['requirements' => 'At least one onboarding requirement is required.']);
        }
        $snapshots = [];
        $actors = [];
        foreach ($requirements as $requirement) {
            $completion = ThirdPartyEngagementOnboardingCompletion::query()->where('third_party_engagement_onboarding_requirement_id', $requirement->id)->orderByDesc('version')->lockForUpdate()->first();
            if ($requirement->required && ! $completion) {
                throw ValidationException::withMessages(['requirements' => 'Every required onboarding requirement must have completion evidence.']);
            }
            $actors[] = $requirement->owner_id;
            if ($completion) {
                $actors[] = $completion->completed_by;
            }
            $snapshots[] = ['requirement' => $requirement->toArray(), 'latest_completion' => $completion?->toArray()];
        }

        return [$snapshots, $actors];
    }

    private function engagementSnapshot(ThirdPartyEngagement $engagement): array
    {
        $engagement->load(['businessOwner:id,name,email', 'proposer:id,name,email', 'approver:id,name,email']);

        return Arr::only($engagement->toArray(), ['id', 'vendor_id', 'code', 'name', 'service_description', 'business_owner_id', 'criticality', 'data_access', 'status', 'term_start_at', 'term_end_at', 'next_review_at', 'approval_snapshot', 'due_diligence_review_snapshot', 'business_owner', 'proposer', 'approver']);
    }

    private function assertManager(User $actor): void
    {
        abort_unless($actor->isSuperAdmin() || $actor->can('Manage Third Party Risk'), 403);
    }

    private function fingerprint(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
