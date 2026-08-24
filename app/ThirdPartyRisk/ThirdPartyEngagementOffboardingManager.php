<?php

namespace App\ThirdPartyRisk;

use App\Enums\ThirdPartyEngagementStatus;
use App\Enums\ThirdPartyOffboardingCategory;
use App\Enums\ThirdPartyOffboardingDecision;
use App\Models\ThirdPartyContractRiskReview;
use App\Models\ThirdPartyEngagement;
use App\Models\ThirdPartyEngagementEvent;
use App\Models\ThirdPartyEngagementOffboardingCompletion;
use App\Models\ThirdPartyEngagementOffboardingReadinessReview;
use App\Models\ThirdPartyEngagementOffboardingRequirement;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ThirdPartyEngagementOffboardingManager
{
    public function define(User $actor, ThirdPartyEngagement $engagement, array $data): ThirdPartyEngagementOffboardingRequirement
    {
        $this->assertManager($actor);

        return DB::transaction(function () use ($actor, $engagement, $data): ThirdPartyEngagementOffboardingRequirement {
            $locked = $this->lockEngagement($engagement);
            $this->assertManager($actor);
            $data = Validator::make($data, self::definitionRules())->validate();
            $this->assertExitCapable($locked);
            if ($locked->offboardingRequirements()->count() >= 100) {
                throw ValidationException::withMessages(['engagement' => 'An engagement is limited to 100 offboarding requirements.']);
            }
            $owner = User::query()->whereNull('deleted_at')->lockForUpdate()->findOrFail($data['owner_id']);
            $due = Carbon::parse($data['due_at'])->toDateString();
            if ($due < today()->toDateString()) {
                throw ValidationException::withMessages(['due_at' => 'The offboarding due date must be current.']);
            }
            $at = now()->startOfSecond();
            $payload = ['third_party_engagement_id' => $locked->id, 'version' => ((int) $locked->offboardingRequirements()->max('version')) + 1,
                'category' => ThirdPartyOffboardingCategory::from($data['category'])->value, 'title' => $data['title'], 'acceptance_criteria' => $data['acceptance_criteria'],
                'owner_id' => $owner->id, 'due_at' => $due, 'required' => (bool) $data['required'], 'engagement_snapshot' => $this->engagementSnapshot($locked),
                'defined_by' => $actor->id, 'defined_at' => $at->toIso8601String()];

            return ThirdPartyEngagementOffboardingRequirement::query()->create($payload + ['fingerprint' => $this->fingerprint($payload)])->load(['owner:id,name', 'definer:id,name']);
        }, 3);
    }

    public function complete(User $actor, ThirdPartyEngagementOffboardingRequirement $requirement, array $data): ThirdPartyEngagementOffboardingCompletion
    {
        return DB::transaction(function () use ($actor, $requirement, $data): ThirdPartyEngagementOffboardingCompletion {
            $engagementId = ThirdPartyEngagementOffboardingRequirement::query()->whereKey($requirement->id)->value('third_party_engagement_id');
            $engagement = $this->lockEngagement(ThirdPartyEngagement::query()->findOrFail($engagementId));
            $locked = ThirdPartyEngagementOffboardingRequirement::query()->where('third_party_engagement_id', $engagement->id)->lockForUpdate()->findOrFail($requirement->id);
            abort_unless($actor->isSuperAdmin() || $actor->can('Manage Third Party Risk') || $actor->id === $locked->owner_id, 403);
            $data = Validator::make($data, self::completionRules())->validate();
            $this->assertExitCapable($engagement);
            if ($locked->completions()->count() >= 10) {
                throw ValidationException::withMessages(['requirement' => 'A requirement is limited to 10 completion versions.']);
            }
            $at = now()->startOfSecond();
            $payload = ['third_party_engagement_offboarding_requirement_id' => $locked->id, 'version' => ((int) $locked->completions()->max('version')) + 1,
                'completion_summary' => $data['completion_summary'], 'source_reference' => $data['source_reference'] ?? null,
                'requirement_snapshot' => $locked->toArray(), 'completed_by' => $actor->id, 'completed_at' => $at->toIso8601String()];

            return ThirdPartyEngagementOffboardingCompletion::query()->create($payload + ['fingerprint' => $this->fingerprint($payload)])->load('completer:id,name');
        }, 3);
    }

    public function review(User $actor, ThirdPartyEngagement $engagement, array $data): ThirdPartyEngagementOffboardingReadinessReview
    {
        $this->assertManager($actor);

        return DB::transaction(function () use ($actor, $engagement, $data): ThirdPartyEngagementOffboardingReadinessReview {
            $locked = $this->lockEngagement($engagement);
            $this->assertManager($actor);
            $data = Validator::make($data, self::reviewRules())->validate();
            $this->assertExitCapable($locked);
            if ($locked->offboardingReadinessReviews()->count() >= 100) {
                throw ValidationException::withMessages(['engagement' => 'An engagement is limited to 100 offboarding readiness reviews.']);
            }
            [$requirements, $actors] = $this->currentRequirementContext($locked);
            $contract = ThirdPartyContractRiskReview::query()->where('third_party_engagement_id', $locked->id)->orderByDesc('version')->lockForUpdate()->firstOrFail();
            $excluded = [$locked->proposed_by, $locked->business_owner_id, $locked->approved_by, $contract->reviewed_by, ...$actors];
            abort_if(in_array($actor->id, array_filter($excluded), true), 403, 'Offboarding readiness review must be independent from engagement, contract, requirement, and completion actors.');
            $decision = ThirdPartyOffboardingDecision::from($data['decision']);
            if ($decision === ThirdPartyOffboardingDecision::ReadyWithConditions && blank($data['conditions'] ?? null)) {
                throw ValidationException::withMessages(['conditions' => 'Conditional readiness requires explicit conditions.']);
            }
            $event = ThirdPartyEngagementEvent::query()->where('third_party_engagement_id', $locked->id)->orderByDesc('version')->lockForUpdate()->firstOrFail();
            $at = now()->startOfSecond();
            $payload = ['third_party_engagement_id' => $locked->id, 'version' => ((int) $locked->offboardingReadinessReviews()->max('version')) + 1,
                'decision' => $decision->value, 'conditions' => $data['conditions'] ?? null, 'summary' => $data['summary'],
                'engagement_snapshot' => $this->engagementSnapshot($locked), 'requirements_snapshot' => $requirements,
                'engagement_event_fingerprint' => $event->fingerprint, 'reviewed_by' => $actor->id, 'reviewed_at' => $at->toIso8601String()];

            return ThirdPartyEngagementOffboardingReadinessReview::query()->create($payload + ['fingerprint' => $this->fingerprint($payload)])->load('reviewer:id,name');
        }, 3);
    }

    public function currentAcceptedReview(ThirdPartyEngagement $engagement, User $exitApprover): ThirdPartyEngagementOffboardingReadinessReview
    {
        $review = ThirdPartyEngagementOffboardingReadinessReview::query()->where('third_party_engagement_id', $engagement->id)->orderByDesc('version')->lockForUpdate()->first();
        abort_if($review?->reviewed_by === $exitApprover->id, 403, 'Exit approval must be separated from the offboarding readiness reviewer.');
        $event = ThirdPartyEngagementEvent::query()->where('third_party_engagement_id', $engagement->id)->orderByDesc('version')->lockForUpdate()->firstOrFail();
        [$requirements] = $this->currentRequirementContext($engagement);
        if (! $review || ! in_array($review->decision, [ThirdPartyOffboardingDecision::Ready, ThirdPartyOffboardingDecision::ReadyWithConditions], true)
            || $review->requirements_snapshot !== $requirements || $review->engagement_event_fingerprint !== $event->fingerprint) {
            throw ValidationException::withMessages(['offboarding_readiness' => 'A current accepted independent offboarding-readiness review is required.']);
        }

        return $review;
    }

    public static function definitionRules(): array
    {
        return ['category' => ['required', Rule::enum(ThirdPartyOffboardingCategory::class)], 'title' => 'required|string|max:255', 'acceptance_criteria' => 'required|string|max:30000',
            'owner_id' => 'required|integer|exists:users,id', 'due_at' => 'required|date_format:Y-m-d', 'required' => 'required|boolean', 'version' => 'prohibited', 'fingerprint' => 'prohibited'];
    }

    public static function completionRules(): array
    {
        return ['completion_summary' => 'required|string|max:30000', 'source_reference' => 'nullable|string|max:255', 'version' => 'prohibited', 'completed_by' => 'prohibited', 'fingerprint' => 'prohibited'];
    }

    public static function reviewRules(): array
    {
        return ['decision' => ['required', Rule::enum(ThirdPartyOffboardingDecision::class)], 'conditions' => 'nullable|string|max:30000', 'summary' => 'required|string|max:30000',
            'version' => 'prohibited', 'requirements_snapshot' => 'prohibited', 'reviewed_by' => 'prohibited', 'fingerprint' => 'prohibited'];
    }

    private function lockEngagement(ThirdPartyEngagement $engagement): ThirdPartyEngagement
    {
        $vendorId = ThirdPartyEngagement::query()->whereKey($engagement->id)->value('vendor_id');
        $vendor = Vendor::withTrashed()->lockForUpdate()->findOrFail($vendorId);

        return ThirdPartyEngagement::query()->where('vendor_id', $vendor->id)->lockForUpdate()->findOrFail($engagement->id);
    }

    private function assertExitCapable(ThirdPartyEngagement $engagement): void
    {
        if (! in_array($engagement->status, [ThirdPartyEngagementStatus::Active, ThirdPartyEngagementStatus::RenewalReview], true)) {
            throw ValidationException::withMessages(['engagement' => 'Offboarding evidence is available only for an active or renewal-review engagement.']);
        }
    }

    private function currentRequirementContext(ThirdPartyEngagement $engagement): array
    {
        $requirements = ThirdPartyEngagementOffboardingRequirement::query()->where('third_party_engagement_id', $engagement->id)->orderBy('id')->lockForUpdate()->get();
        if ($requirements->isEmpty()) {
            throw ValidationException::withMessages(['requirements' => 'At least one offboarding requirement is required.']);
        }
        $snapshots = [];
        $actors = [];
        foreach ($requirements as $requirement) {
            $completions = ThirdPartyEngagementOffboardingCompletion::query()->where('third_party_engagement_offboarding_requirement_id', $requirement->id)->orderBy('version')->lockForUpdate()->get();
            $completion = $completions->last();
            if ($requirement->required && ! $completion) {
                throw ValidationException::withMessages(['requirements' => 'Every required offboarding requirement must have completion evidence.']);
            }
            $actors[] = $requirement->owner_id;
            $actors = [...$actors, ...$completions->pluck('completed_by')->all()];
            $snapshots[] = ['requirement' => $requirement->toArray(), 'latest_completion' => $completion?->toArray()];
        }

        return [$snapshots, $actors];
    }

    private function engagementSnapshot(ThirdPartyEngagement $engagement): array
    {
        $engagement->load(['businessOwner:id,name,email', 'proposer:id,name,email', 'approver:id,name,email']);

        return Arr::only($engagement->toArray(), ['id', 'vendor_id', 'code', 'name', 'service_description', 'business_owner_id', 'criticality', 'data_access', 'status', 'term_start_at', 'term_end_at', 'next_review_at', 'approval_snapshot', 'onboarding_readiness_snapshot', 'business_owner', 'proposer', 'approver']);
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
