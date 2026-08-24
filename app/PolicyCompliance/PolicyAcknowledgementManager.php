<?php

namespace App\PolicyCompliance;

use App\Models\Policy;
use App\Models\PolicyAcknowledgement;
use App\Models\PolicyAcknowledgementAssignment;
use App\Models\PolicyAcknowledgementCampaign;
use App\Models\User;
use App\Notifications\PolicyAcknowledgementAssigned;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PolicyAcknowledgementManager
{
    public const STATEMENT = 'I acknowledge that I have read and understand the policy version assigned to me.';

    public function launch(Policy $policy, User $actor, array $data): PolicyAcknowledgementCampaign
    {
        return DB::transaction(function () use ($policy, $actor, $data): PolicyAcknowledgementCampaign {
            $locked = Policy::query()->lockForUpdate()->findOrFail($policy->id);
            $this->authorizeManage($locked, $actor);
            $validated = Validator::make($data, self::launchRules())->validate();
            if (! $locked->effective_date || $locked->effective_date->isFuture() || $locked->retired_date?->endOfDay()->isPast()) {
                throw ValidationException::withMessages(['policy' => 'Acknowledgement campaigns require a currently effective, non-retired policy.']);
            }
            if (blank($locked->body)) {
                throw ValidationException::withMessages(['policy' => 'The policy must contain readable body content before an acknowledgement campaign can launch.']);
            }
            $audienceIds = collect($validated['audience_user_ids'])->map(fn ($id): int => (int) $id)->unique()->sort()->values();
            $users = User::query()->whereKey($audienceIds)->lockForUpdate()->get();
            if ($users->count() !== $audienceIds->count()) {
                throw ValidationException::withMessages(['audience_user_ids' => 'Every audience member must be an active user.']);
            }
            $policySnapshot = $locked->only([
                'id', 'code', 'name', 'document_type', 'policy_scope', 'purpose', 'body', 'document_path',
                'scope_id', 'department_id', 'status_id', 'owner_id', 'effective_date', 'retired_date', 'revision_history', 'updated_at',
            ]);
            $fingerprint = hash('sha256', json_encode($policySnapshot, JSON_THROW_ON_ERROR));
            $launchedAt = now();
            $version = ((int) $locked->acknowledgementCampaigns()->max('version')) + 1;
            $campaign = $locked->acknowledgementCampaigns()->create([
                'version' => $version, 'title' => $validated['title'], 'instructions' => $validated['instructions'] ?? null,
                'due_at' => $validated['due_at'], 'launched_by' => $actor->id, 'launched_at' => $launchedAt,
                'policy_snapshot' => $policySnapshot, 'policy_fingerprint' => $fingerprint,
            ]);
            $campaign->assignments()->createMany($audienceIds->map(fn (int $userId): array => [
                'user_id' => $userId, 'assigned_at' => $launchedAt,
            ])->all());

            $usersById = $users->keyBy('id');
            foreach ($campaign->assignments()->orderBy('user_id')->get() as $assignment) {
                $recipient = $usersById->get($assignment->user_id);
                $notificationId = Str::uuid()->toString();
                $attemptedAt = now()->startOfSecond();
                $recipient->notifyNow(new PolicyAcknowledgementAssigned(
                    $notificationId,
                    $campaign->title,
                    $locked->code,
                    $campaign->due_at->toISOString(),
                    $assignment->id,
                ));
                if (! DB::table('notifications')->where('id', $notificationId)
                    ->where('notifiable_type', User::class)->where('notifiable_id', $recipient->id)->exists()) {
                    throw new \LogicException('The acknowledgement notification was not accepted by the database delivery channel.');
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
                $deliveryPayload = [
                    'policy_acknowledgement_assignment_id' => $assignment->id,
                    'policy_acknowledgement_campaign_id' => $campaign->id,
                    'user_id' => $recipient->id,
                    'channel' => 'database',
                    'notification_id' => $notificationId,
                    'recipient_snapshot' => $recipientSnapshot,
                    'campaign_snapshot' => $campaignSnapshot,
                    'attempted_at' => $attemptedAt->toISOString(),
                    'delivered_at' => $deliveredAt->toISOString(),
                ];
                $assignment->delivery()->create($deliveryPayload + [
                    'fingerprint' => hash('sha256', json_encode($deliveryPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
                ]);
            }

            return $campaign->load(['launcher:id,name', 'assignments.user:id,name,email', 'assignments.delivery']);
        }, 3);
    }

    public function acknowledge(PolicyAcknowledgementAssignment $assignment, User $actor, array $data): PolicyAcknowledgement
    {
        return DB::transaction(function () use ($assignment, $actor, $data): PolicyAcknowledgement {
            $locked = PolicyAcknowledgementAssignment::query()->lockForUpdate()->findOrFail($assignment->id);
            $campaign = PolicyAcknowledgementCampaign::query()->lockForUpdate()->findOrFail($locked->policy_acknowledgement_campaign_id);
            if ($locked->user_id !== $actor->id) {
                abort(403, 'Only the assigned user can acknowledge this policy campaign.');
            }
            $validated = Validator::make($data, self::acknowledgementRules())->validate();
            if ($campaign->closed_at) {
                throw ValidationException::withMessages(['campaign' => 'This acknowledgement campaign is closed.']);
            }
            if ($locked->acknowledgement()->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['acknowledgement' => 'This assignment has already been acknowledged.']);
            }
            $acknowledgedAt = now();

            return $locked->acknowledgement()->create([
                'acknowledged_by' => $actor->id,
                'statement' => self::STATEMENT,
                'comment' => $validated['comment'] ?? null,
                'client_reference' => $validated['client_reference'] ?? null,
                'campaign_snapshot' => [
                    'id' => $campaign->id, 'version' => $campaign->version, 'title' => $campaign->title,
                    'instructions' => $campaign->instructions, 'due_at' => $campaign->due_at,
                    'launched_by' => $campaign->launched_by, 'launched_at' => $campaign->launched_at,
                ],
                'policy_snapshot' => $campaign->policy_snapshot,
                'policy_fingerprint' => $campaign->policy_fingerprint,
                'acknowledged_at' => $acknowledgedAt,
            ])->load(['acknowledger:id,name,email', 'assignment.campaign.policy:id,code,name']);
        }, 3);
    }

    public function close(PolicyAcknowledgementCampaign $campaign, User $actor): PolicyAcknowledgementCampaign
    {
        return DB::transaction(function () use ($campaign, $actor): PolicyAcknowledgementCampaign {
            $policyId = PolicyAcknowledgementCampaign::query()->whereKey($campaign->id)->value('policy_id');
            $policy = Policy::withTrashed()->lockForUpdate()->findOrFail($policyId);
            $locked = PolicyAcknowledgementCampaign::query()->lockForUpdate()->findOrFail($campaign->id);
            $this->authorizeManage($policy, $actor);
            if ($locked->closed_at) {
                throw ValidationException::withMessages(['campaign' => 'This acknowledgement campaign is already closed.']);
            }
            $locked->update(['closed_by' => $actor->id, 'closed_at' => now()]);

            return $locked->load(['closer:id,name'])->loadCount(['assignments', 'assignments as acknowledged_count' => fn ($query) => $query->has('acknowledgement')]);
        }, 3);
    }

    public function assignments(User $actor): Builder
    {
        return PolicyAcknowledgementAssignment::query()->where('user_id', $actor->id)
            ->with(['campaign.policy:id,code,name', 'delivery', 'reminders', 'acknowledgement:id,policy_acknowledgement_assignment_id,acknowledged_at'])
            ->latest('assigned_at');
    }

    public function report(PolicyAcknowledgementCampaign $campaign, User $actor): Builder
    {
        $policy = $campaign->relationLoaded('policy') ? $campaign->policy : $campaign->policy()->firstOrFail();
        $this->authorizeManage($policy, $actor);

        return $campaign->assignments()->getQuery()->with(['campaign', 'user:id,name,email', 'delivery', 'reminders', 'acknowledgement.acknowledger:id,name,email'])->latest('assigned_at');
    }

    public static function launchRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'instructions' => ['nullable', 'string', 'max:10000'],
            'due_at' => ['required', 'date', 'after:now'],
            'audience_user_ids' => ['required', 'array', 'min:1', 'max:500'],
            'audience_user_ids.*' => ['required', 'integer', 'distinct', 'exists:users,id'],
        ];
    }

    public static function acknowledgementRules(): array
    {
        return [
            'acknowledged' => ['required', 'accepted'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'client_reference' => ['nullable', 'string', 'max:255'],
        ];
    }

    private function authorizeManage(Policy $policy, User $actor): void
    {
        if (! $actor->can('Update Policies') && $policy->owner_id !== $actor->id) {
            abort(403, 'You cannot manage acknowledgement campaigns for this policy.');
        }
    }
}
