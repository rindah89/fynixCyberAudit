<?php

namespace App\ThirdPartyRisk;

use App\Enums\ThirdPartyEngagementStatus;
use App\Enums\ThirdPartyRiskDecisionType;
use App\Enums\VendorStatus;
use App\Models\ThirdPartyEngagement;
use App\Models\ThirdPartyEngagementEvent;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ThirdPartyEngagementManager
{
    public function propose(User $actor, Vendor $vendor, array $data): ThirdPartyEngagement
    {
        $this->assertCanManage($actor);
        $data = Validator::make($data, self::proposalRules())->validate();

        return DB::transaction(function () use ($actor, $vendor, $data): ThirdPartyEngagement {
            $lockedVendor = Vendor::withTrashed()->lockForUpdate()->findOrFail($vendor->id);
            $this->assertCanManage($actor);
            if ($lockedVendor->trashed() || in_array($lockedVendor->status, [VendorStatus::REJECTED, VendorStatus::TERMINATED], true)) {
                throw ValidationException::withMessages(['vendor_id' => 'Rejected, terminated, or deleted vendors cannot receive new engagements.']);
            }
            if ($lockedVendor->engagements()->count() >= 100) {
                throw ValidationException::withMessages(['vendor_id' => 'A vendor is limited to 100 retained engagements.']);
            }
            $owner = User::query()->whereNull('deleted_at')->lockForUpdate()->findOrFail($data['business_owner_id']);
            $start = Carbon::parse($data['term_start_at'])->toDateString();
            $end = Carbon::parse($data['term_end_at'])->toDateString();
            $review = Carbon::parse($data['next_review_at'])->toDateString();
            if ($end < $start || $review < $start || $review > $end) {
                throw ValidationException::withMessages(['term_end_at' => 'Term end must follow start and the review date must fall within the term.']);
            }
            $at = now()->startOfSecond();
            $engagement = $lockedVendor->engagements()->create([
                ...$data, 'business_owner_id' => $owner->id, 'term_start_at' => $start, 'term_end_at' => $end, 'next_review_at' => $review,
                'status' => ThirdPartyEngagementStatus::Proposed, 'proposed_by' => $actor->id, 'vendor_snapshot' => $this->vendorSnapshot($lockedVendor), 'governed_at' => $at,
            ]);
            $this->appendEvent($engagement, $actor, null, ThirdPartyEngagementStatus::Proposed, 'Third-party engagement proposed for governed due diligence.', $at);

            return $engagement->fresh(['businessOwner:id,name,email', 'proposer:id,name', 'events.actor:id,name']);
        }, 3);
    }

    public function transition(User $actor, ThirdPartyEngagement $engagement, array $data): ThirdPartyEngagementEvent
    {
        $this->assertCanManage($actor);

        return DB::transaction(function () use ($actor, $engagement, $data): ThirdPartyEngagementEvent {
            $vendorId = ThirdPartyEngagement::query()->whereKey($engagement->id)->value('vendor_id');
            $vendor = Vendor::withTrashed()->lockForUpdate()->findOrFail($vendorId);
            $locked = ThirdPartyEngagement::query()->where('vendor_id', $vendor->id)->lockForUpdate()->findOrFail($engagement->id);
            $this->assertCanManage($actor);
            $data = Validator::make($data, self::transitionRules())->validate();
            $next = ThirdPartyEngagementStatus::from($data['status']);
            if (! in_array($next, $locked->status->allowedNext(), true)) {
                throw ValidationException::withMessages(['status' => 'The engagement must advance through an allowed next state.']);
            }
            if ($locked->events()->count() >= 100) {
                throw ValidationException::withMessages(['engagement' => 'An engagement is limited to 100 retained events.']);
            }
            $changes = ['status' => $next];
            if ($next === ThirdPartyEngagementStatus::Approved || ($locked->status === ThirdPartyEngagementStatus::RenewalReview && $next === ThirdPartyEngagementStatus::Active)) {
                [$assessment, $decision, $snapshot] = $this->approvalContext($vendor);
                abort_if(in_array($actor->id, [$locked->proposed_by, $assessment->assessor_id, $decision->decided_by], true), 403, 'Engagement approval must be separated from proposal and current risk assessment/decision actors.');
                $changes += ['approval_snapshot' => $snapshot, 'approved_by' => $actor->id, 'approved_at' => now()->startOfSecond()];
                if ($locked->status === ThirdPartyEngagementStatus::RenewalReview) {
                    if (blank($data['renewed_term_end_at'] ?? null) || blank($data['renewed_next_review_at'] ?? null)) {
                        throw ValidationException::withMessages(['renewed_term_end_at' => 'Renewal requires a new term end and review date.']);
                    }
                    $newEnd = Carbon::parse($data['renewed_term_end_at'])->toDateString();
                    $newReview = Carbon::parse($data['renewed_next_review_at'])->toDateString();
                    if ($newEnd <= $locked->term_end_at->toDateString() || $newReview < today()->toDateString() || $newReview > $newEnd) {
                        throw ValidationException::withMessages(['renewed_term_end_at' => 'Renewal must extend the term and retain a current review date within it.']);
                    }
                    $changes += ['term_end_at' => $newEnd, 'next_review_at' => $newReview];
                }
            }
            if ($next === ThirdPartyEngagementStatus::Active && $locked->status === ThirdPartyEngagementStatus::Approved) {
                if ($locked->term_start_at->isFuture()) {
                    throw ValidationException::withMessages(['status' => 'An engagement cannot become active before its retained term start.']);
                }
                $this->assertApprovalStillCurrent($vendor, $locked);
                $changes['activated_at'] = now()->startOfSecond();
            }
            if ($next === ThirdPartyEngagementStatus::Exited) {
                $changes += ['exit_summary' => $data['exit_summary'], 'data_disposition_statement' => $data['data_disposition_statement'], 'exited_at' => now()->startOfSecond()];
            }
            $from = $locked->status;
            $locked->update($changes);

            return $this->appendEvent($locked->refresh(), $actor, $from, $next, $data['summary'], now()->startOfSecond())->load('actor:id,name');
        }, 3);
    }

    public static function proposalRules(): array
    {
        return ['code' => ['required', 'string', 'max:100', 'regex:/^[A-Z0-9][A-Z0-9._-]*$/'], 'name' => 'required|string|max:255', 'service_description' => 'required|string|max:30000',
            'business_owner_id' => 'required|integer|exists:users,id', 'criticality' => 'required|in:low,medium,high,critical', 'data_access' => 'required|boolean',
            'term_start_at' => 'required|date', 'term_end_at' => 'required|date', 'next_review_at' => 'required|date',
            'status' => 'prohibited', 'proposed_by' => 'prohibited', 'vendor_snapshot' => 'prohibited', 'approval_snapshot' => 'prohibited', 'governed_at' => 'prohibited'];
    }

    public static function transitionRules(): array
    {
        return ['status' => ['required', Rule::enum(ThirdPartyEngagementStatus::class)], 'summary' => 'required|string|max:10000',
            'renewed_term_end_at' => 'nullable|date', 'renewed_next_review_at' => 'nullable|date',
            'exit_summary' => 'required_if:status,exited|nullable|string|max:30000', 'data_disposition_statement' => 'required_if:status,exited|nullable|string|max:30000',
            'version' => 'prohibited', 'engagement_snapshot' => 'prohibited', 'recorded_by' => 'prohibited', 'fingerprint' => 'prohibited'];
    }

    private function approvalContext(Vendor $vendor): array
    {
        $assessment = $vendor->latestRiskAssessment()->lockForUpdate()->first();
        $decision = $vendor->latestRiskDecision()->lockForUpdate()->first();
        $risks = $vendor->risks()->orderBy('risks.id')->lockForUpdate()->get();
        $vendor->setRelation('risks', $risks);
        if (! $assessment || ! $decision || ! in_array($decision->decision, [ThirdPartyRiskDecisionType::Approved, ThirdPartyRiskDecisionType::ConditionallyApproved], true)
            || $decision->vendor_risk_assessment_id !== $assessment->id || $decision->expires_at?->copy()->endOfDay()->isPast()) {
            throw ValidationException::withMessages(['approval' => 'A current latest vendor risk approval is required.']);
        }
        $governance = $vendor->thirdPartyRiskSnapshot($assessment);
        if ($decision->governance_fingerprint !== $governance['fingerprint']) {
            throw ValidationException::withMessages(['approval' => 'Current vendor governance differs from its risk approval.']);
        }
        $snapshot = ['assessment' => $assessment->toArray(), 'decision' => $decision->toArray(), 'governance' => $governance];

        return [$assessment, $decision, $snapshot];
    }

    private function assertApprovalStillCurrent(Vendor $vendor, ThirdPartyEngagement $engagement): void
    {
        [, $decision, $snapshot] = $this->approvalContext($vendor);
        if (($engagement->approval_snapshot['decision']['id'] ?? null) !== $decision->id || ($engagement->approval_snapshot['governance']['fingerprint'] ?? null) !== $snapshot['governance']['fingerprint']) {
            throw ValidationException::withMessages(['approval' => 'The engagement approval is stale; return through governed due diligence.']);
        }
    }

    private function vendorSnapshot(Vendor $vendor): array
    {
        return Arr::only($vendor->toArray(), ['id', 'name', 'description', 'url', 'vendor_manager_id', 'contact_name', 'contact_email', 'contact_phone', 'address', 'status', 'risk_rating']);
    }

    private function appendEvent(ThirdPartyEngagement $engagement, User $actor, ?ThirdPartyEngagementStatus $from, ThirdPartyEngagementStatus $to, string $summary, Carbon $at): ThirdPartyEngagementEvent
    {
        $engagement->load(['businessOwner:id,name,email', 'proposer:id,name,email', 'approver:id,name,email']);
        $snapshot = Arr::only($engagement->toArray(), ['id', 'vendor_id', 'code', 'name', 'service_description', 'criticality', 'data_access', 'status', 'term_start_at', 'term_end_at', 'next_review_at', 'approved_at', 'activated_at', 'exited_at', 'exit_summary', 'data_disposition_statement', 'vendor_snapshot', 'approval_snapshot', 'governed_at'])
            + ['business_owner' => $engagement->businessOwner?->only(['id', 'name', 'email']), 'proposer' => $engagement->proposer?->only(['id', 'name', 'email']), 'approver' => $engagement->approver?->only(['id', 'name', 'email'])];
        $payload = ['third_party_engagement_id' => $engagement->id, 'version' => ((int) $engagement->events()->max('version')) + 1, 'from_status' => $from?->value, 'to_status' => $to->value,
            'summary' => $summary, 'engagement_snapshot' => $snapshot, 'recorded_by' => $actor->id, 'recorded_at' => $at->toIso8601String()];

        return $engagement->events()->create($payload + ['fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))]);
    }

    private function assertCanManage(User $actor): void
    {
        abort_unless($actor->isSuperAdmin() || $actor->can('Manage Third Party Risk'), 403, 'You cannot manage third-party engagements.');
    }
}
