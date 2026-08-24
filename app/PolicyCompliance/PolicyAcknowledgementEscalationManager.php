<?php

namespace App\PolicyCompliance;

use App\Models\Policy;
use App\Models\PolicyAcknowledgementAssignment;
use App\Models\PolicyAcknowledgementCampaign;
use App\Models\User;
use App\Notifications\PolicyAcknowledgementEscalationNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PolicyAcknowledgementEscalationManager
{
    public const OVERDUE_GRACE_DAYS = 3;

    public function reconcile(?Carbon $asOf = null): int
    {
        $asOf = ($asOf ?? now())->copy()->startOfSecond();
        $delivered = 0;
        PolicyAcknowledgementAssignment::query()
            ->whereDoesntHave('acknowledgement')->whereDoesntHave('escalation')
            ->whereHas('campaign', fn ($query) => $query->whereNull('closed_at')->where('due_at', '<', $asOf->copy()->subDays(self::OVERDUE_GRACE_DAYS)))
            ->orderBy('id')->select('id')->chunkById(500, function ($assignments) use ($asOf, &$delivered): void {
                foreach ($assignments as $assignment) {
                    if ($this->escalate($assignment->id, $asOf)) {
                        $delivered++;
                    }
                }
            });

        return $delivered;
    }

    private function escalate(int $assignmentId, Carbon $asOf): bool
    {
        return DB::transaction(function () use ($assignmentId, $asOf): bool {
            $campaignId = PolicyAcknowledgementAssignment::query()->whereKey($assignmentId)
                ->value('policy_acknowledgement_campaign_id');
            $policyId = PolicyAcknowledgementCampaign::query()->whereKey($campaignId)->value('policy_id');
            $policy = Policy::withTrashed()->lockForUpdate()->findOrFail($policyId);
            $this->afterPolicyLock($policy);
            $campaign = PolicyAcknowledgementCampaign::query()->lockForUpdate()->findOrFail($campaignId);
            $assignment = PolicyAcknowledgementAssignment::query()->lockForUpdate()->findOrFail($assignmentId);
            if ($assignment->policy_acknowledgement_campaign_id !== $campaign->id || $campaign->policy_id !== $policy->id) {
                return false;
            }
            if ($campaign->closed_at || ! $campaign->due_at->lessThan($asOf->copy()->subDays(self::OVERDUE_GRACE_DAYS))
                || $assignment->acknowledgement()->lockForUpdate()->exists() || $assignment->escalation()->lockForUpdate()->exists()) {
                return false;
            }
            $assignedUser = User::query()->lockForUpdate()->find($assignment->user_id);
            if (! $assignedUser) {
                return false;
            }
            $recipient = User::query()->lockForUpdate()->find($policy->owner_id);
            if (! $recipient) {
                return false;
            }
            $notificationId = Str::uuid()->toString();
            $attemptedAt = now()->startOfSecond();
            $recipient->notifyNow(new PolicyAcknowledgementEscalationNotification(
                $notificationId, $campaign->title, (string) data_get($campaign->policy_snapshot, 'code'),
                $assignedUser->name, $campaign->due_at->toISOString(), $assignment->id,
            ));
            if (! DB::table('notifications')->where('id', $notificationId)->where('notifiable_type', User::class)->where('notifiable_id', $recipient->id)->exists()) {
                throw new \LogicException('The acknowledgement escalation was not accepted by the database delivery channel.');
            }
            $deliveredAt = now()->startOfSecond();
            $payload = [
                'policy_acknowledgement_assignment_id' => $assignment->id,
                'policy_acknowledgement_campaign_id' => $campaign->id,
                'assigned_user_id' => $assignedUser->id,
                'escalated_to_user_id' => $recipient->id,
                'channel' => 'database', 'notification_id' => $notificationId,
                'assignment_snapshot' => $assignedUser->only(['id', 'name', 'email']),
                'recipient_snapshot' => $recipient->only(['id', 'name']),
                'campaign_snapshot' => [
                    'id' => $campaign->id, 'policy_id' => $campaign->policy_id, 'version' => $campaign->version,
                    'title' => $campaign->title, 'due_at' => $campaign->due_at->toISOString(),
                    'policy_fingerprint' => $campaign->policy_fingerprint,
                ],
                'attempted_at' => $attemptedAt->toISOString(), 'delivered_at' => $deliveredAt->toISOString(),
            ];
            $assignment->escalation()->create($payload + ['fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))]);

            return true;
        }, 3);
    }

    protected function afterPolicyLock(Policy $policy): void
    {
        // Test seam for exercising the cross-process policy-first lock boundary.
    }
}
