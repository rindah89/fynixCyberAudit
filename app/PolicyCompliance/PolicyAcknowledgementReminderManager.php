<?php

namespace App\PolicyCompliance;

use App\Enums\PolicyAcknowledgementReminderType;
use App\Models\PolicyAcknowledgementAssignment;
use App\Models\PolicyAcknowledgementCampaign;
use App\Models\User;
use App\Notifications\PolicyAcknowledgementReminderNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PolicyAcknowledgementReminderManager
{
    public const DUE_SOON_DAYS = 3;

    public function reconcile(?Carbon $asOf = null): int
    {
        $asOf = ($asOf ?? now())->copy()->startOfSecond();
        $delivered = 0;

        PolicyAcknowledgementAssignment::query()
            ->whereDoesntHave('acknowledgement')
            ->whereHas('campaign', fn ($query) => $query->whereNull('closed_at')
                ->where('due_at', '<=', $asOf->copy()->addDays(self::DUE_SOON_DAYS)))
            ->whereHas('user')
            ->orderBy('id')
            ->select('id')
            ->chunkById(500, function ($assignments) use ($asOf, &$delivered): void {
                foreach ($assignments as $assignment) {
                    if ($this->remind($assignment->id, $asOf)) {
                        $delivered++;
                    }
                }
            });

        return $delivered;
    }

    private function remind(int $assignmentId, Carbon $asOf): bool
    {
        return DB::transaction(function () use ($assignmentId, $asOf): bool {
            $assignment = PolicyAcknowledgementAssignment::query()->lockForUpdate()->findOrFail($assignmentId);
            $campaign = PolicyAcknowledgementCampaign::query()->lockForUpdate()
                ->findOrFail($assignment->policy_acknowledgement_campaign_id);
            if ($campaign->closed_at || $assignment->acknowledgement()->lockForUpdate()->exists()
                || $campaign->due_at->greaterThan($asOf->copy()->addDays(self::DUE_SOON_DAYS))) {
                return false;
            }
            $type = $campaign->due_at->lessThan($asOf)
                ? PolicyAcknowledgementReminderType::Overdue
                : PolicyAcknowledgementReminderType::DueSoon;
            if ($assignment->reminders()->where('type', $type)->lockForUpdate()->exists()) {
                return false;
            }
            $recipient = User::query()->lockForUpdate()->find($assignment->user_id);
            if (! $recipient) {
                return false;
            }

            $notificationId = Str::uuid()->toString();
            $attemptedAt = now()->startOfSecond();
            $recipient->notifyNow(new PolicyAcknowledgementReminderNotification(
                $notificationId,
                $type,
                $campaign->title,
                (string) data_get($campaign->policy_snapshot, 'code'),
                $campaign->due_at->toISOString(),
                $assignment->id,
            ));
            if (! DB::table('notifications')->where('id', $notificationId)
                ->where('notifiable_type', User::class)->where('notifiable_id', $recipient->id)->exists()) {
                throw new \LogicException('The acknowledgement reminder was not accepted by the database delivery channel.');
            }
            $deliveredAt = now()->startOfSecond();
            $recipientSnapshot = $recipient->only(['id', 'name', 'email']);
            $campaignSnapshot = [
                'id' => $campaign->id,
                'policy_id' => $campaign->policy_id,
                'version' => $campaign->version,
                'title' => $campaign->title,
                'instructions' => $campaign->instructions,
                'due_at' => $campaign->due_at->toISOString(),
                'launched_by' => $campaign->launched_by,
                'launched_at' => $campaign->launched_at->toISOString(),
                'policy_fingerprint' => $campaign->policy_fingerprint,
            ];
            $payload = [
                'policy_acknowledgement_assignment_id' => $assignment->id,
                'policy_acknowledgement_campaign_id' => $campaign->id,
                'user_id' => $recipient->id,
                'type' => $type->value,
                'channel' => 'database',
                'notification_id' => $notificationId,
                'recipient_snapshot' => $recipientSnapshot,
                'campaign_snapshot' => $campaignSnapshot,
                'attempted_at' => $attemptedAt->toISOString(),
                'delivered_at' => $deliveredAt->toISOString(),
            ];
            $assignment->reminders()->create($payload + [
                'fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            ]);

            return true;
        }, 3);
    }
}
