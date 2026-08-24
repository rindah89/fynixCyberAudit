<?php

namespace App\Privacy;

use App\Enums\PrivacyRightsRequestStatus;
use App\Enums\PrivacyRightsRequestType;
use App\Models\PrivacyRightsRequest;
use App\Models\PrivacyRightsRequestEvent;
use App\Models\User;
use App\Support\Enterprise;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PrivacyRightsRequestManager
{
    public function open(User $actor, array $data): PrivacyRightsRequest
    {
        $this->assertCanOpen($actor);
        $data = Validator::make($data, self::openRules())->validate();

        return DB::transaction(function () use ($actor, $data): PrivacyRightsRequest {
            DB::table('privacy_activity_mutexes')->where('id', 1)->lockForUpdate()->first();
            $this->assertCanOpen($actor);
            $assignee = $this->lockAuthorizedHandler((int) $data['assigned_to']);
            $receivedAt = Carbon::parse($data['received_at'])->startOfSecond();
            $dueAt = Carbon::parse($data['due_at'])->startOfSecond();
            if ($dueAt->lt($receivedAt)) {
                throw ValidationException::withMessages(['due_at' => 'The deliberate due time cannot precede receipt.']);
            }
            $now = now()->startOfSecond();
            $next = ((int) PrivacyRightsRequest::query()->max('id')) + 1;
            $request = PrivacyRightsRequest::query()->create([
                ...$data, 'assigned_to' => $assignee->id, 'number' => 'PRR-'.$now->format('Y').'-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT),
                'status' => PrivacyRightsRequestStatus::Received, 'opened_by' => $actor->id, 'received_at' => $receivedAt, 'due_at' => $dueAt, 'governed_at' => $now,
            ]);
            $this->appendEvent($request, $actor, null, PrivacyRightsRequestStatus::Received, 'Privacy rights request recorded through the stated intake channel.', $now);

            return $request->fresh(['assignee:id,name,email', 'opener:id,name', 'events.actor:id,name']);
        }, 3);
    }

    public function transition(User $actor, PrivacyRightsRequest $request, array $data): PrivacyRightsRequestEvent
    {
        Enterprise::assertEnabled('privacy_management');

        return DB::transaction(function () use ($actor, $request, $data): PrivacyRightsRequestEvent {
            $locked = PrivacyRightsRequest::query()->lockForUpdate()->findOrFail($request->id);
            abort_unless($actor->can('update', $locked), 403, 'You cannot handle this privacy rights request.');
            if (array_key_exists('assigned_to', $data)) {
                abort_unless($actor->can('Manage Privacy Rights'), 403, 'Only a privacy-rights manager can reassign requests.');
            }
            $data = Validator::make($data, self::transitionRules())->validate();
            $next = PrivacyRightsRequestStatus::from($data['status']);
            if (! in_array($next, $locked->status->allowedNext(), true)) {
                throw ValidationException::withMessages(['status' => 'The rights request must advance through an allowed next state.']);
            }
            $events = $locked->events()->lockForUpdate()->get();
            if ($events->count() >= 200) {
                throw ValidationException::withMessages(['request' => 'A privacy rights request is limited to 200 retained events.']);
            }
            $changes = ['status' => $next];
            if (array_key_exists('assigned_to', $data)) {
                $changes['assigned_to'] = $this->lockAuthorizedHandler((int) $data['assigned_to'])->id;
            }
            if ($next === PrivacyRightsRequestStatus::InProgress) {
                $changes['identity_verification_summary'] = $data['identity_verification_summary'];
            }
            if ($next === PrivacyRightsRequestStatus::Fulfilled) {
                $changes += ['response_summary' => $data['response_summary'], 'delivery_reference' => $data['delivery_reference'], 'completed_at' => now()->startOfSecond()];
            }
            if ($next === PrivacyRightsRequestStatus::Denied) {
                $changes += ['decision_basis' => $data['decision_basis'], 'completed_at' => now()->startOfSecond()];
            }
            if ($next === PrivacyRightsRequestStatus::Withdrawn) {
                $changes['completed_at'] = now()->startOfSecond();
            }
            $from = $locked->status;
            $locked->update($changes);

            return $this->appendEvent($locked->refresh(), $actor, $from, $next, $data['summary'], now()->startOfSecond())->load('actor:id,name');
        }, 3);
    }

    public static function openRules(): array
    {
        return [
            'number' => 'prohibited', 'status' => 'prohibited', 'opened_by' => 'prohibited', 'governed_at' => 'prohibited', 'completed_at' => 'prohibited',
            'request_type' => ['required', Rule::enum(PrivacyRightsRequestType::class)], 'data_subject_name' => 'required|string|max:255',
            'data_subject_email' => 'nullable|email|max:255', 'subject_reference' => 'nullable|string|max:255',
            'request_details' => 'required|string|max:30000', 'intake_channel' => 'required|string|max:255', 'jurisdiction_reference' => 'nullable|string|max:2000',
            'received_at' => 'required|date|before_or_equal:now', 'due_at' => 'required|date', 'assigned_to' => 'required|integer|exists:users,id',
        ];
    }

    public static function transitionRules(): array
    {
        return [
            'version' => 'prohibited', 'fingerprint' => 'prohibited', 'request_snapshot' => 'prohibited', 'recorded_by' => 'prohibited', 'completed_at' => 'prohibited',
            'status' => ['required', Rule::enum(PrivacyRightsRequestStatus::class)], 'summary' => 'required|string|max:10000',
            'assigned_to' => 'sometimes|integer|exists:users,id',
            'identity_verification_summary' => 'required_if:status,in_progress|nullable|string|max:30000',
            'response_summary' => 'required_if:status,fulfilled|nullable|string|max:30000',
            'delivery_reference' => 'required_if:status,fulfilled|nullable|string|max:2000',
            'decision_basis' => 'required_if:status,denied|nullable|string|max:30000',
        ];
    }

    private function appendEvent(PrivacyRightsRequest $request, User $actor, ?PrivacyRightsRequestStatus $from, PrivacyRightsRequestStatus $to, string $summary, Carbon $at): PrivacyRightsRequestEvent
    {
        $payload = ['privacy_rights_request_id' => $request->id, 'version' => ((int) $request->events()->max('version')) + 1,
            'from_status' => $from?->value, 'to_status' => $to->value, 'summary' => $summary, 'request_snapshot' => $this->snapshot($request),
            'recorded_by' => $actor->id, 'recorded_at' => $at->toIso8601String()];

        return $request->events()->create($payload + ['fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))]);
    }

    private function snapshot(PrivacyRightsRequest $request): array
    {
        $request->load(['assignee:id,name,email', 'opener:id,name,email']);
        $snapshot = Arr::only($request->toArray(), ['id', 'number', 'request_type', 'status', 'data_subject_name', 'data_subject_email', 'subject_reference', 'request_details', 'intake_channel', 'jurisdiction_reference', 'received_at', 'due_at', 'identity_verification_summary', 'response_summary', 'decision_basis', 'delivery_reference', 'completed_at', 'governed_at'])
            + ['assignee' => $request->assignee?->only(['id', 'name', 'email']), 'opener' => $request->opener?->only(['id', 'name', 'email'])];

        return json_decode(json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), true, flags: JSON_THROW_ON_ERROR);
    }

    private function lockAuthorizedHandler(int $id): User
    {
        $handler = User::query()->whereNull('deleted_at')->lockForUpdate()->findOrFail($id);
        if (! $handler->can('Handle Privacy Rights') && ! $handler->can('Manage Privacy Rights')) {
            throw ValidationException::withMessages(['assigned_to' => 'The assignee must be an active authorized privacy-rights handler.']);
        }

        return $handler;
    }

    private function assertCanOpen(User $actor): void
    {
        Enterprise::assertEnabled('privacy_management');
        abort_unless($actor->isSuperAdmin() || $actor->can('Manage Privacy Rights'), 403, 'You cannot open privacy rights requests.');
    }
}
