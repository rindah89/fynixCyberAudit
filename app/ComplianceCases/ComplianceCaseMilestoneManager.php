<?php

namespace App\ComplianceCases;

use App\Enums\ComplianceCaseMilestoneStatus;
use App\Enums\ComplianceCaseStatus;
use App\Models\ComplianceCase;
use App\Models\ComplianceCaseEvent;
use App\Models\ComplianceCaseMilestone;
use App\Models\ComplianceCaseMilestoneDelivery;
use App\Models\ComplianceCaseMilestoneEvent;
use App\Models\User;
use App\Notifications\ComplianceCaseMilestoneNotification;
use App\Support\CanonicalJson;
use App\Support\Enterprise;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ComplianceCaseMilestoneManager
{
    /** @param array{title:string,description:string,owner_id:int,due_at:string,required?:bool} $data */
    public function define(User $actor, ComplianceCase $case, array $data): ComplianceCaseMilestone
    {
        Enterprise::assertEnabled('compliance_cases');

        return DB::transaction(function () use ($actor, $case, $data): ComplianceCaseMilestone {
            $locked = ComplianceCase::query()->lockForUpdate()->findOrFail($case->id);
            abort_unless($actor->can('Manage Compliance Cases') && $actor->can('view', $locked), 403);
            app(ComplianceCaseConflictManager::class)->assertClear($actor, $locked);
            if ($locked->status === ComplianceCaseStatus::Closed) {
                throw ValidationException::withMessages(['case' => 'Closed compliance cases reject new milestones.']);
            }
            $data = Validator::make($data, self::defineRules())->validate();
            $owner = User::query()->whereNull('deleted_at')->lockForUpdate()->findOrFail($data['owner_id']);
            $event = ComplianceCaseEvent::query()->where('compliance_case_id', $locked->id)->orderByDesc('version')->lockForUpdate()->firstOrFail();
            $existing = ComplianceCaseMilestone::query()->where('compliance_case_id', $locked->id)->orderBy('version')->lockForUpdate()->get();
            if ($existing->count() >= 20) {
                throw ValidationException::withMessages(['case' => 'A governed compliance case is limited to 20 milestones.']);
            }
            $dueAt = Carbon::parse($data['due_at'])->utc();
            $definedAt = now()->startOfSecond();
            $milestone = new ComplianceCaseMilestone([
                'compliance_case_id' => $locked->id, 'compliance_case_event_id' => $event->id,
                'version' => $existing->count() + 1, 'title' => trim($data['title']),
                'description' => trim($data['description']), 'owner_id' => $owner->id,
                'owner_snapshot' => $owner->only(['id', 'name', 'email']),
                'due_at' => $dueAt, 'required' => (bool) ($data['required'] ?? true),
                'status' => ComplianceCaseMilestoneStatus::Open, 'defined_by' => $actor->id,
                'definer_snapshot' => $actor->only(['id', 'name', 'email']),
                'case_snapshot' => app(ComplianceCaseInvestigationPlanManager::class)->caseSnapshot($locked, $event),
                'defined_at' => $definedAt,
            ]);
            $milestone->fingerprint = hash('sha256', CanonicalJson::encode($this->payload($milestone)));
            $milestone->save();

            return $milestone->load(['owner:id,name,email']);
        }, 3);
    }

    /** @param array{summary:string} $data */
    public function complete(User $actor, ComplianceCaseMilestone $milestone, array $data): ComplianceCaseMilestoneEvent
    {
        Enterprise::assertEnabled('compliance_cases');

        return DB::transaction(function () use ($actor, $milestone, $data): ComplianceCaseMilestoneEvent {
            $case = ComplianceCase::query()->lockForUpdate()->findOrFail($milestone->compliance_case_id);
            $locked = ComplianceCaseMilestone::query()->where('compliance_case_id', $case->id)->lockForUpdate()->findOrFail($milestone->id);
            abort_unless($actor->id === $locked->owner_id || ($actor->can('Manage Compliance Cases') && $actor->can('view', $case)), 403);
            app(ComplianceCaseConflictManager::class)->assertClear($actor, $case);
            $data = Validator::make($data, ['summary' => 'required|string|max:30000'])->validate();
            if ($locked->status !== ComplianceCaseMilestoneStatus::Open) {
                throw ValidationException::withMessages(['milestone' => 'Only an open milestone can be completed.']);
            }
            $locked->status = ComplianceCaseMilestoneStatus::Completed;
            $locked->save();

            return $this->appendEvent($locked, $actor, 'completed', trim($data['summary']));
        }, 3);
    }

    /** @param array{summary:string} $data */
    public function waive(User $actor, ComplianceCaseMilestone $milestone, array $data): ComplianceCaseMilestoneEvent
    {
        Enterprise::assertEnabled('compliance_cases');

        return DB::transaction(function () use ($actor, $milestone, $data): ComplianceCaseMilestoneEvent {
            $case = ComplianceCase::query()->lockForUpdate()->findOrFail($milestone->compliance_case_id);
            $locked = ComplianceCaseMilestone::query()->where('compliance_case_id', $case->id)->lockForUpdate()->findOrFail($milestone->id);
            abort_unless($actor->can('Manage Compliance Cases') && $actor->can('view', $case), 403);
            abort_if($actor->id === $locked->defined_by || $actor->id === $locked->owner_id, 403, 'A separated manager must waive the milestone.');
            app(ComplianceCaseConflictManager::class)->assertClear($actor, $case);
            $data = Validator::make($data, ['summary' => 'required|string|max:30000'])->validate();
            if ($locked->status !== ComplianceCaseMilestoneStatus::Open) {
                throw ValidationException::withMessages(['milestone' => 'Only an open milestone can be waived.']);
            }
            $locked->status = ComplianceCaseMilestoneStatus::Waived;
            $locked->save();

            return $this->appendEvent($locked, $actor, 'waived', trim($data['summary']));
        }, 3);
    }

    public function history(User $actor, ComplianceCase $case, int $perPage): LengthAwarePaginator
    {
        Enterprise::assertEnabled('compliance_cases');
        abort_unless($actor->can('view', $case), 403);

        return $case->milestones()->with(['owner:id,name,email', 'events', 'deliveries.recipient:id,name,email'])->paginate($perPage);
    }

    public function hasBlockingRequiredMilestones(ComplianceCase $case): bool
    {
        return ComplianceCaseMilestone::query()->where('compliance_case_id', $case->id)
            ->where('required', true)->where('status', ComplianceCaseMilestoneStatus::Open->value)->exists();
    }

    public function reconcile(?Carbon $asOf = null): int
    {
        $asOf = ($asOf ?? now())->copy()->utc();
        $delivered = 0;
        $open = ComplianceCaseMilestone::query()->where('status', ComplianceCaseMilestoneStatus::Open->value)->orderBy('id')->get();
        foreach ($open as $milestone) {
            $delivered += DB::transaction(function () use ($milestone, $asOf): int {
                $case = ComplianceCase::query()->lockForUpdate()->find($milestone->compliance_case_id);
                if ($case === null) {
                    return 0;
                }
                $locked = ComplianceCaseMilestone::query()->where('compliance_case_id', $case->id)->lockForUpdate()->find($milestone->id);
                if ($locked === null || $locked->status !== ComplianceCaseMilestoneStatus::Open) {
                    return 0;
                }
                $due = $locked->due_at->copy()->utc();
                $type = null;
                if ($asOf->greaterThan($due)) {
                    $type = 'overdue';
                } elseif ($asOf->greaterThanOrEqualTo($due->copy()->subDays(3))) {
                    $type = 'due_soon';
                }
                if ($type === null || $locked->events()->where('event_type', $type)->exists()) {
                    return 0;
                }
                $event = $this->appendEvent($locked, null, $type, $type === 'overdue' ? 'The milestone due time has ended.' : 'The milestone is due soon.');
                $recipient = User::query()->whereNull('deleted_at')->lockForUpdate()->find($locked->owner_id);
                if ($recipient === null) {
                    throw ValidationException::withMessages(['owner' => 'The active milestone owner is required for delivery.']);
                }
                $notificationId = Str::uuid()->toString();
                $attemptedAt = now()->startOfSecond();
                $recipient->notifyNow(new ComplianceCaseMilestoneNotification(
                    $notificationId, $locked->id, $locked->title, $type, $locked->due_at->toIso8601String(),
                ));
                if (! DB::table('notifications')->where('id', $notificationId)
                    ->where('notifiable_type', User::class)->where('notifiable_id', $recipient->id)->exists()) {
                    throw new \LogicException('The milestone reminder was not accepted by the database delivery channel.');
                }
                $deliveredAt = now()->startOfSecond();
                $payload = [
                    'compliance_case_milestone_id' => $locked->id,
                    'compliance_case_milestone_event_id' => $event->id,
                    'user_id' => $recipient->id, 'event_type' => $type, 'channel' => 'database',
                    'notification_id' => $notificationId,
                    'recipient_snapshot' => $recipient->only(['id', 'name', 'email']),
                    'milestone_snapshot' => ['id' => $locked->id, 'fingerprint' => $locked->fingerprint] + $this->payload($locked),
                    'attempted_at' => $attemptedAt->toIso8601String(),
                    'delivered_at' => $deliveredAt->toIso8601String(),
                ];
                ComplianceCaseMilestoneDelivery::query()->create($payload + [
                    'fingerprint' => hash('sha256', CanonicalJson::encode($payload)),
                ]);

                return 1;
            });
        }

        return $delivered;
    }

    /** @return array<string,mixed> */
    public function payload(ComplianceCaseMilestone $milestone): array
    {
        return [
            'compliance_case_id' => $milestone->compliance_case_id, 'compliance_case_event_id' => $milestone->compliance_case_event_id,
            'version' => $milestone->version, 'title' => $milestone->title, 'description' => $milestone->description,
            'owner_id' => $milestone->owner_id, 'owner_snapshot' => $milestone->owner_snapshot,
            'due_at' => $milestone->due_at?->toIso8601String(), 'required' => (bool) $milestone->required,
            'defined_by' => $milestone->defined_by, 'definer_snapshot' => $milestone->definer_snapshot,
            'case_snapshot' => $milestone->case_snapshot, 'defined_at' => $milestone->defined_at?->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> */
    public static function defineRules(): array
    {
        return [
            'title' => 'required|string|max:255', 'description' => 'required|string|max:30000',
            'owner_id' => 'required|integer|exists:users,id', 'due_at' => 'required|date',
            'required' => 'sometimes|boolean', 'id' => 'prohibited', 'version' => 'prohibited', 'fingerprint' => 'prohibited',
            'defined_by' => 'prohibited', 'defined_at' => 'prohibited', 'status' => 'prohibited',
        ];
    }

    private function appendEvent(ComplianceCaseMilestone $milestone, ?User $actor, string $type, string $summary): ComplianceCaseMilestoneEvent
    {
        $recordedAt = now()->startOfSecond();
        $payload = [
            'compliance_case_milestone_id' => $milestone->id, 'event_type' => $type, 'summary' => $summary,
            'recorded_by' => $actor?->id, 'actor_snapshot' => $actor?->only(['id', 'name', 'email']),
            'milestone_snapshot' => $this->payload($milestone) + ['status' => $milestone->status instanceof \BackedEnum ? $milestone->status->value : $milestone->status],
            'recorded_at' => $recordedAt->toIso8601String(),
        ];

        return $milestone->events()->create($payload + ['fingerprint' => hash('sha256', CanonicalJson::encode($payload))]);
    }
}
