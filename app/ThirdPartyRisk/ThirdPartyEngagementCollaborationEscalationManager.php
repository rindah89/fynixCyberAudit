<?php

namespace App\ThirdPartyRisk;

use App\Enums\ThirdPartyCollaborationReminderType;
use App\Enums\ThirdPartyCollaborationStatus;
use App\Enums\ThirdPartyEngagementStatus;
use App\Models\ThirdPartyEngagement;
use App\Models\ThirdPartyEngagementCollaborationEvent;
use App\Models\ThirdPartyEngagementCollaborationReminder;
use App\Models\ThirdPartyEngagementCollaborationRequest;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorUser;
use App\Notifications\ThirdPartyCollaborationEscalationNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ThirdPartyEngagementCollaborationEscalationManager
{
    public const OVERDUE_GRACE_DAYS = 3;

    public function reconcile(?Carbon $asOf = null): int
    {
        $asOf = ($asOf ?? now())->copy()->startOfSecond();
        $delivered = 0;

        ThirdPartyEngagementCollaborationRequest::query()
            ->whereDoesntHave('escalation')
            ->whereHas('reminders', fn ($query) => $query->where('type', ThirdPartyCollaborationReminderType::Overdue))
            ->whereDate('due_at', '<', $asOf->copy()->subDays(self::OVERDUE_GRACE_DAYS)->toDateString())
            ->orderBy('id')->select('id')->chunkById(500, function ($requests) use ($asOf, &$delivered): void {
                foreach ($requests as $request) {
                    if ($this->escalate($request->id, $asOf)) {
                        $delivered++;
                    }
                }
            });

        return $delivered;
    }

    private function escalate(int $requestId, Carbon $asOf): bool
    {
        return DB::transaction(function () use ($requestId, $asOf): bool {
            $engagementId = ThirdPartyEngagementCollaborationRequest::query()->whereKey($requestId)->value('third_party_engagement_id');
            if (! $engagementId) {
                return false;
            }
            $vendorId = ThirdPartyEngagement::query()->whereKey($engagementId)->value('vendor_id');
            if (! $vendorId) {
                return false;
            }
            $vendor = Vendor::withTrashed()->lockForUpdate()->find($vendorId);
            $engagement = $vendor ? ThirdPartyEngagement::query()->where('vendor_id', $vendor->id)->lockForUpdate()->find($engagementId) : null;
            $request = $engagement ? ThirdPartyEngagementCollaborationRequest::query()->where('third_party_engagement_id', $engagement->id)->lockForUpdate()->find($requestId) : null;
            if (! $request || ! in_array($engagement->status, [ThirdPartyEngagementStatus::DueDiligence, ThirdPartyEngagementStatus::Approved, ThirdPartyEngagementStatus::Active, ThirdPartyEngagementStatus::RenewalReview], true)
                || $request->escalation()->lockForUpdate()->exists()) {
                return false;
            }
            $reassignments = $request->reassignments()->orderBy('version')->lockForUpdate()->get();
            $recipientContext = $request->setRelation('reassignments', $reassignments)->currentRecipientContext();
            $vendorRecipient = VendorUser::query()->lockForUpdate()->find($recipientContext['recipient_vendor_user_id']);
            if (! $vendorRecipient || $vendorRecipient->vendor_id !== $engagement->vendor_id || ! $vendorRecipient->hasPassword()) {
                return false;
            }
            $event = ThirdPartyEngagementCollaborationEvent::query()
                ->where('third_party_engagement_collaboration_request_id', $request->id)
                ->orderByDesc('version')->lockForUpdate()->first();
            if (! $event || ! in_array($event->status, [ThirdPartyCollaborationStatus::Requested, ThirdPartyCollaborationStatus::FollowUp], true)) {
                return false;
            }
            $extensions = $request->extensions()->with('decision')->orderBy('version')->lockForUpdate()->get();
            $dueContext = $request->setRelation('extensions', $extensions)->effectiveDueContext();
            $effectiveDue = Carbon::parse($dueContext['due_at']);
            if (! $effectiveDue->copy()->endOfDay()->addDays(self::OVERDUE_GRACE_DAYS)->lessThan($asOf)) {
                return false;
            }
            $overdueReminder = ThirdPartyEngagementCollaborationReminder::query()
                ->where('third_party_engagement_collaboration_request_id', $request->id)
                ->where('due_context_fingerprint', $dueContext['fingerprint'])
                ->where('type', ThirdPartyCollaborationReminderType::Overdue)->lockForUpdate()->first();
            if (! $overdueReminder) {
                return false;
            }

            $rolesByUser = collect([
                ['id' => $engagement->business_owner_id, 'role' => 'business_owner'],
                ['id' => $vendor->vendor_manager_id, 'role' => 'vendor_manager'],
            ])->filter(fn (array $entry): bool => $entry['id'] !== null)
                ->map(fn (array $entry): array => ['id' => (int) $entry['id'], 'role' => $entry['role']])
                ->groupBy('id')->map(fn ($entries): array => $entries->pluck('role')->sort()->values()->all());
            $recipients = User::query()->whereIn('id', $rolesByUser->keys())->orderBy('id')->lockForUpdate()->get();
            if ($recipients->isEmpty()) {
                return false;
            }

            $attemptedAt = now()->startOfSecond();
            $notificationIds = [];
            $recipientSnapshots = [];
            foreach ($recipients as $recipient) {
                $notificationId = Str::uuid()->toString();
                $recipient->notifyNow(new ThirdPartyCollaborationEscalationNotification(
                    $notificationId,
                    $engagement->code,
                    $request->subject,
                    $vendorRecipient->name,
                    $effectiveDue->toDateString(),
                    $request->id,
                ));
                if (! DB::table('notifications')->where('id', $notificationId)
                    ->where('notifiable_type', User::class)->where('notifiable_id', $recipient->id)->exists()) {
                    throw new \LogicException('The collaboration escalation was not accepted by the database delivery channel.');
                }
                $notificationIds[] = $notificationId;
                $recipientSnapshots[] = $recipient->only(['id', 'name', 'email']) + ['roles' => $rolesByUser->get($recipient->id)];
            }
            $deliveredAt = now()->startOfSecond();
            $payload = [
                'third_party_engagement_collaboration_request_id' => $request->id,
                'third_party_engagement_id' => $engagement->id,
                'vendor_user_id' => $vendorRecipient->id,
                'effective_due_at' => $effectiveDue->toDateString(),
                'due_context_snapshot' => $dueContext,
                'channel' => 'database',
                'notification_ids' => $notificationIds,
                'recipient_snapshots' => $recipientSnapshots,
                'request_snapshot' => $request->attributesToArray(),
                'event_snapshot' => $this->eventSnapshot($event),
                'overdue_reminder_snapshot' => $overdueReminder->attributesToArray(),
                'attempted_at' => $attemptedAt->toIso8601String(),
                'delivered_at' => $deliveredAt->toIso8601String(),
            ];
            $request->escalation()->create($payload + [
                'fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            ]);

            return true;
        }, 3);
    }

    private function eventSnapshot(ThirdPartyEngagementCollaborationEvent $event): array
    {
        return [
            'id' => $event->id,
            'third_party_engagement_collaboration_request_id' => $event->third_party_engagement_collaboration_request_id,
            'version' => $event->version,
            'status' => $event->status->value,
            'response_text' => $event->response_text,
            'source_reference' => $event->source_reference,
            'summary' => $event->summary,
            'actor_type' => $event->actor_type,
            'actor_id' => $event->actor_id,
            'actor_snapshot' => $event->actor_snapshot,
            'request_snapshot' => $event->request_snapshot,
            'evidence_manifest' => $event->evidence_manifest ?? [],
            'recorded_at' => $event->recorded_at->toIso8601String(),
            'fingerprint' => $event->fingerprint,
        ];
    }
}
