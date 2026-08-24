<?php

namespace App\ThirdPartyRisk;

use App\Enums\ThirdPartyCollaborationReminderType;
use App\Enums\ThirdPartyCollaborationStatus;
use App\Enums\ThirdPartyEngagementStatus;
use App\Models\ThirdPartyEngagement;
use App\Models\ThirdPartyEngagementCollaborationEvent;
use App\Models\ThirdPartyEngagementCollaborationRequest;
use App\Models\Vendor;
use App\Models\VendorUser;
use App\Notifications\ThirdPartyCollaborationReminderNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ThirdPartyEngagementCollaborationReminderManager
{
    public const DUE_SOON_DAYS = 3;

    public function reconcile(?Carbon $asOf = null): int
    {
        $asOf = ($asOf ?? now())->copy()->startOfSecond();
        $delivered = 0;

        ThirdPartyEngagementCollaborationRequest::query()
            ->whereDate('due_at', '<=', $asOf->copy()->addDays(self::DUE_SOON_DAYS)->toDateString())
            ->orderBy('id')->select('id')->chunkById(500, function ($requests) use ($asOf, &$delivered): void {
                foreach ($requests as $request) {
                    if ($this->remind($request->id, $asOf)) {
                        $delivered++;
                    }
                }
            });

        return $delivered;
    }

    private function remind(int $requestId, Carbon $asOf): bool
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
            if (! $request || ! in_array($engagement->status, [ThirdPartyEngagementStatus::DueDiligence, ThirdPartyEngagementStatus::Approved, ThirdPartyEngagementStatus::Active, ThirdPartyEngagementStatus::RenewalReview], true)) {
                return false;
            }
            $reassignments = $request->reassignments()->orderBy('version')->lockForUpdate()->get();
            $recipientContext = $request->setRelation('reassignments', $reassignments)->currentRecipientContext();
            $recipient = VendorUser::query()->lockForUpdate()->find($recipientContext['recipient_vendor_user_id']);
            if (! $recipient || $recipient->vendor_id !== $engagement->vendor_id || ! $recipient->hasPassword()) {
                return false;
            }
            $event = ThirdPartyEngagementCollaborationEvent::query()
                ->where('third_party_engagement_collaboration_request_id', $request->id)
                ->orderByDesc('version')->lockForUpdate()->first();
            $extensions = $request->extensions()->with('decision')->orderBy('version')->lockForUpdate()->get();
            if ($request->cancellation()->lockForUpdate()->exists()) {
                return false;
            }
            $dueContext = $request->setRelation('extensions', $extensions)->effectiveDueContext();
            $effectiveDue = Carbon::parse($dueContext['due_at']);
            if (! $event || ! in_array($event->status, [ThirdPartyCollaborationStatus::Requested, ThirdPartyCollaborationStatus::FollowUp], true)
                || $effectiveDue->greaterThan($asOf->copy()->addDays(self::DUE_SOON_DAYS)->endOfDay())) {
                return false;
            }
            $type = $effectiveDue->copy()->endOfDay()->lessThan($asOf)
                ? ThirdPartyCollaborationReminderType::Overdue
                : ThirdPartyCollaborationReminderType::DueSoon;
            if ($request->reminders()->where('type', $type)->lockForUpdate()->exists()) {
                return false;
            }
            $notificationId = Str::uuid()->toString();
            $attemptedAt = now()->startOfSecond();
            $recipient->notifyNow(new ThirdPartyCollaborationReminderNotification(
                $notificationId,
                $type,
                $engagement->code,
                $request->subject,
                $effectiveDue->toDateString(),
                $request->id,
            ));
            if (! DB::table('notifications')->where('id', $notificationId)
                ->where('notifiable_type', VendorUser::class)->where('notifiable_id', $recipient->id)->exists()) {
                throw new \LogicException('The collaboration reminder was not accepted by the database delivery channel.');
            }
            $deliveredAt = now()->startOfSecond();
            $payload = [
                'third_party_engagement_collaboration_request_id' => $request->id,
                'third_party_engagement_id' => $engagement->id,
                'vendor_user_id' => $recipient->id,
                'type' => $type->value,
                'due_context_fingerprint' => $dueContext['fingerprint'],
                'effective_due_at' => $effectiveDue->toDateString(),
                'due_context_snapshot' => $dueContext,
                'channel' => 'database',
                'notification_id' => $notificationId,
                'recipient_snapshot' => $recipient->only(['id', 'vendor_id', 'name', 'email', 'is_primary']),
                'request_snapshot' => $request->attributesToArray(),
                'event_snapshot' => $event->attributesToArray(),
                'attempted_at' => $attemptedAt->toIso8601String(),
                'delivered_at' => $deliveredAt->toIso8601String(),
            ];
            $request->reminders()->create($payload + [
                'fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            ]);

            return true;
        }, 3);
    }
}
