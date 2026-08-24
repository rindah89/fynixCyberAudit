<?php

namespace App\ThirdPartyRisk;

use App\Enums\ThirdPartyCollaborationExtensionDecision as ExtensionDecision;
use App\Enums\ThirdPartyCollaborationReminderType;
use App\Enums\ThirdPartyCollaborationStatus;
use App\Enums\ThirdPartyEngagementStatus;
use App\Models\ThirdPartyCollaborationExtension;
use App\Models\ThirdPartyCollaborationExtensionDecision;
use App\Models\ThirdPartyEngagement;
use App\Models\ThirdPartyEngagementCollaborationEvent;
use App\Models\ThirdPartyEngagementCollaborationRequest;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ThirdPartyEngagementCollaborationExtensionManager
{
    public function request(VendorUser $actor, ThirdPartyEngagementCollaborationRequest $request, array $data): ThirdPartyCollaborationExtension
    {
        return DB::transaction(function () use ($actor, $request, $data): ThirdPartyCollaborationExtension {
            [$locked, $engagement] = $this->lockRequest($request);
            $reassignments = $locked->reassignments()->orderBy('version')->lockForUpdate()->get();
            $recipientContext = $locked->setRelation('reassignments', $reassignments)->currentRecipientContext();
            $recipient = VendorUser::query()->whereNull('deleted_at')->lockForUpdate()->find($recipientContext['recipient_vendor_user_id']);
            abort_unless($recipient?->hasPassword() && $recipient->id === $actor->id && $recipient->vendor_id === $engagement->vendor_id, 403);
            $latestEvent = $this->latestEvent($locked);
            $extensions = $locked->extensions()->with('decision')->orderBy('version')->lockForUpdate()->get();
            $validated = Validator::make($data, self::requestRules())->validate();
            $this->assertEligible($locked, $engagement, $latestEvent);
            if ($extensions->count() >= 20) {
                throw ValidationException::withMessages(['request' => 'A collaboration request is limited to 20 due-date extension requests.']);
            }
            if ($extensions->contains(fn (ThirdPartyCollaborationExtension $extension): bool => $extension->decision === null)) {
                throw ValidationException::withMessages(['request' => 'The current due-date extension request requires a decision first.']);
            }
            $currentContext = $locked->setRelation('extensions', $extensions)->effectiveDueContext();
            $proposedDue = Carbon::parse($validated['proposed_due_at'])->toDateString();
            if ($proposedDue <= $currentContext['due_at'] || $proposedDue > $engagement->term_end_at->toDateString() || $proposedDue < today()->toDateString()) {
                throw ValidationException::withMessages(['proposed_due_at' => 'The proposed due date must extend the current due date, remain current, and fall within the engagement term.']);
            }
            $requestedAt = now()->startOfSecond();
            $payload = [
                'third_party_engagement_collaboration_request_id' => $locked->id,
                'version' => ((int) $extensions->max('version')) + 1,
                'proposed_due_at' => $proposedDue,
                'reason' => $validated['reason'],
                'recipient_vendor_user_id' => $recipient->id,
                'recipient_snapshot' => $recipient->only(['id', 'vendor_id', 'name', 'email', 'is_primary']),
                'request_snapshot' => $locked->attributesToArray(),
                'current_due_context' => $currentContext,
                'requested_at' => $requestedAt->toIso8601String(),
            ];

            return $locked->extensions()->create($payload + ['fingerprint' => $this->fingerprint($payload)]);
        }, 3);
    }

    public function decide(User $actor, ThirdPartyCollaborationExtension $extension, array $data): ThirdPartyCollaborationExtensionDecision
    {
        return DB::transaction(function () use ($actor, $extension, $data): ThirdPartyCollaborationExtensionDecision {
            $requestId = ThirdPartyCollaborationExtension::query()->whereKey($extension->id)->value('third_party_engagement_collaboration_request_id');
            [$request, $engagement] = $this->lockRequest(ThirdPartyEngagementCollaborationRequest::query()->findOrFail($requestId));
            $reassignments = $request->reassignments()->orderBy('version')->lockForUpdate()->get();
            $recipientContext = $request->setRelation('reassignments', $reassignments)->currentRecipientContext();
            VendorUser::withTrashed()->lockForUpdate()->findOrFail($recipientContext['recipient_vendor_user_id']);
            $latestEvent = $this->latestEvent($request);
            $extensions = $request->extensions()->with('decision')->orderBy('version')->lockForUpdate()->get();
            $locked = $extensions->firstWhere('id', $extension->id) ?? throw ValidationException::withMessages(['extension' => 'The extension does not belong to the current request.']);
            $lockedActor = User::query()->lockForUpdate()->find($actor->id);
            abort_unless($lockedActor && ($lockedActor->isSuperAdmin() || $lockedActor->can('Manage Third Party Risk')), 403);
            abort_if($lockedActor->id === $request->opened_by, 403, 'Extension review must be separated from the request opener.');
            $validated = Validator::make($data, self::decisionRules())->validate();
            $this->assertEligible($request, $engagement, $latestEvent);
            if ($locked->decision !== null || $extensions->last()?->id !== $locked->id) {
                throw ValidationException::withMessages(['extension' => 'Only the latest pending extension request can be decided.']);
            }
            $decision = ExtensionDecision::from($validated['decision']);
            if ($decision === ExtensionDecision::Approved && $locked->proposed_due_at->toDateString() < today()->toDateString()) {
                throw ValidationException::withMessages(['proposed_due_at' => 'A past proposed due date cannot be approved.']);
            }
            $decidedAt = now()->startOfSecond();
            $payload = [
                'third_party_collaboration_extension_id' => $locked->id,
                'decision' => $decision->value,
                'summary' => $validated['summary'],
                'decided_by' => $lockedActor->id,
                'decider_snapshot' => $lockedActor->only(['id', 'name', 'email']),
                'extension_snapshot' => $locked->attributesToArray(),
                'decided_at' => $decidedAt->toIso8601String(),
            ];

            return $locked->decision()->create($payload + ['fingerprint' => $this->fingerprint($payload)]);
        }, 3);
    }

    public static function requestRules(): array
    {
        return [
            'proposed_due_at' => 'required|date_format:Y-m-d', 'reason' => 'required|string|max:30000',
            'version' => 'prohibited', 'recipient_vendor_user_id' => 'prohibited', 'recipient_snapshot' => 'prohibited',
            'request_snapshot' => 'prohibited', 'current_due_context' => 'prohibited', 'requested_at' => 'prohibited', 'fingerprint' => 'prohibited',
        ];
    }

    public static function decisionRules(): array
    {
        return [
            'decision' => ['required', Rule::enum(ExtensionDecision::class)], 'summary' => 'required|string|max:30000',
            'decided_by' => 'prohibited', 'decider_snapshot' => 'prohibited', 'extension_snapshot' => 'prohibited',
            'decided_at' => 'prohibited', 'fingerprint' => 'prohibited',
        ];
    }

    private function lockRequest(ThirdPartyEngagementCollaborationRequest $request): array
    {
        $engagementId = ThirdPartyEngagementCollaborationRequest::query()->whereKey($request->id)->value('third_party_engagement_id');
        $vendorId = ThirdPartyEngagement::query()->whereKey($engagementId)->value('vendor_id');
        $vendor = Vendor::withTrashed()->lockForUpdate()->findOrFail($vendorId);
        $engagement = ThirdPartyEngagement::query()->where('vendor_id', $vendor->id)->lockForUpdate()->findOrFail($engagementId);
        $locked = ThirdPartyEngagementCollaborationRequest::query()->where('third_party_engagement_id', $engagement->id)->lockForUpdate()->findOrFail($request->id);

        return [$locked, $engagement];
    }

    private function latestEvent(ThirdPartyEngagementCollaborationRequest $request): ThirdPartyEngagementCollaborationEvent
    {
        return ThirdPartyEngagementCollaborationEvent::query()->where('third_party_engagement_collaboration_request_id', $request->id)->orderByDesc('version')->lockForUpdate()->firstOrFail();
    }

    private function assertEligible(ThirdPartyEngagementCollaborationRequest $request, ThirdPartyEngagement $engagement, ThirdPartyEngagementCollaborationEvent $latestEvent): void
    {
        if (! in_array($engagement->status, [ThirdPartyEngagementStatus::DueDiligence, ThirdPartyEngagementStatus::Approved, ThirdPartyEngagementStatus::Active, ThirdPartyEngagementStatus::RenewalReview], true)
            || ! in_array($latestEvent->status, [ThirdPartyCollaborationStatus::Requested, ThirdPartyCollaborationStatus::FollowUp], true)
            || $request->reminders()->where('type', ThirdPartyCollaborationReminderType::Overdue)->lockForUpdate()->exists()
            || $request->escalation()->lockForUpdate()->exists()
            || $request->cancellation()->lockForUpdate()->exists()) {
            throw ValidationException::withMessages(['request' => 'Due-date extension is available only for an awaiting, non-escalated collaboration request.']);
        }
    }

    private function fingerprint(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
