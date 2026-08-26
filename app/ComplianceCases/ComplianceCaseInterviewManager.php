<?php

namespace App\ComplianceCases;

use App\Enums\ComplianceCaseInterviewStatus;
use App\Enums\ComplianceCaseStatus;
use App\Models\ComplianceCase;
use App\Models\ComplianceCaseInterview;
use App\Models\ComplianceCaseInterviewEvent;
use App\Models\User;
use App\Support\CanonicalJson;
use App\Support\Enterprise;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ComplianceCaseInterviewManager
{
    /** @param array<string,mixed> $data */
    public function schedule(User $actor, ComplianceCase $case, array $data): ComplianceCaseInterview
    {
        Enterprise::assertEnabled('compliance_cases');

        return DB::transaction(function () use ($actor, $case, $data): ComplianceCaseInterview {
            $lockedCase = ComplianceCase::query()->lockForUpdate()->findOrFail($case->id);
            $this->authorizeMutation($actor, $lockedCase);
            $data = Validator::make($data, self::scheduleRules())->validate();
            $interviews = ComplianceCaseInterview::query()->where('compliance_case_id', $lockedCase->id)->orderBy('id')->lockForUpdate()->get();
            if ($interviews->count() >= 100) {
                throw ValidationException::withMessages(['case' => 'A governed compliance case is limited to 100 interviews.']);
            }
            $this->assertInterviewable($lockedCase);
            $interviewer = $this->lockInterviewer((int) $data['interviewer_id']);
            $subject = isset($data['subject_user_id']) ? User::query()->whereNull('deleted_at')->lockForUpdate()->findOrFail($data['subject_user_id']) : null;
            if ($subject === null && blank($data['subject_reference'] ?? null)) {
                throw ValidationException::withMessages(['subject_reference' => 'An internal subject or external subject reference is required.']);
            }
            if ($subject !== null && ! blank($data['subject_reference'] ?? null)) {
                throw ValidationException::withMessages(['subject_reference' => 'Choose either an internal subject or an external subject reference.']);
            }
            $recordedAt = now();
            $interview = ComplianceCaseInterview::query()->create([
                ...Arr::only($data, ['subject_user_id', 'subject_reference', 'interviewer_id', 'scheduled_at', 'location', 'purpose']),
                'compliance_case_id' => $lockedCase->id,
                'interviewer_id' => $interviewer->id,
                'status' => ComplianceCaseInterviewStatus::Scheduled,
            ]);
            $this->appendEvent($interview, $actor, null, $this->snapshot($interview), 'scheduled', $data['rationale'], $recordedAt, 1);

            return $interview->load(['subjectUser:id,name,email', 'interviewer:id,name,email', 'events.actor:id,name,email']);
        }, 3);
    }

    /** @param array<string,mixed> $data */
    public function record(User $actor, ComplianceCase $case, ComplianceCaseInterview $interview, array $data): ComplianceCaseInterviewEvent
    {
        Enterprise::assertEnabled('compliance_cases');

        return DB::transaction(function () use ($actor, $case, $interview, $data): ComplianceCaseInterviewEvent {
            $lockedCase = ComplianceCase::query()->lockForUpdate()->findOrFail($case->id);
            $locked = ComplianceCaseInterview::query()->where('compliance_case_id', $lockedCase->id)->lockForUpdate()->findOrFail($interview->id);
            $this->authorizeMutation($actor, $lockedCase);
            $data = Validator::make($data, self::eventRules())->validate();
            $events = ComplianceCaseInterviewEvent::query()->where('compliance_case_interview_id', $locked->id)->orderBy('id')->lockForUpdate()->get();
            if ($events->count() >= 20) {
                throw ValidationException::withMessages(['interview' => 'A governed compliance interview is limited to 20 events.']);
            }
            $recordedAt = now();
            $this->assertInterviewable($lockedCase);
            if ($locked->status !== ComplianceCaseInterviewStatus::Scheduled) {
                throw ValidationException::withMessages(['interview' => 'Conducted and cancelled interviews are terminal.']);
            }
            if (isset($data['interviewer_id'])) {
                abort_unless($actor->can('Manage Compliance Cases'), 403);
                $this->lockInterviewer((int) $data['interviewer_id']);
            }
            $before = $this->snapshot($locked);
            $changes = Arr::only($data, ['interviewer_id', 'scheduled_at', 'location', 'purpose', 'summary', 'conducted_at', 'cancellation_reason']);
            $status = isset($data['status']) ? ComplianceCaseInterviewStatus::from($data['status']) : $locked->status;
            if ($status === ComplianceCaseInterviewStatus::Conducted) {
                if (blank($changes['summary'] ?? $locked->summary) || blank($changes['conducted_at'] ?? $locked->conducted_at)) {
                    throw ValidationException::withMessages(['status' => 'A conducted interview requires its conducted time and deliberate summary.']);
                }
                if (array_key_exists('cancellation_reason', $changes)) {
                    throw ValidationException::withMessages(['cancellation_reason' => 'A conducted interview cannot retain a cancellation reason.']);
                }
                if (Carbon::parse($changes['conducted_at'] ?? $locked->conducted_at)->isAfter($recordedAt)) {
                    throw ValidationException::withMessages(['conducted_at' => 'The actual interview time cannot be in the future.']);
                }
            }
            if ($status === ComplianceCaseInterviewStatus::Cancelled && blank($changes['cancellation_reason'] ?? $locked->cancellation_reason)) {
                throw ValidationException::withMessages(['status' => 'A cancelled interview requires a cancellation reason.']);
            }
            if ($status === ComplianceCaseInterviewStatus::Cancelled && array_intersect(array_keys($changes), ['summary', 'conducted_at']) !== []) {
                throw ValidationException::withMessages(['status' => 'A cancelled interview cannot retain conducted outcome evidence.']);
            }
            if ($status === ComplianceCaseInterviewStatus::Scheduled && array_intersect(array_keys($changes), ['summary', 'conducted_at', 'cancellation_reason']) !== []) {
                throw ValidationException::withMessages(['status' => 'Outcome evidence requires a conducted or cancelled decision.']);
            }
            $changes['status'] = $status;
            $locked->update($changes);
            $after = $this->snapshot($locked->refresh());
            if ($before === $after) {
                throw ValidationException::withMessages(['interview' => 'An interview event must change governed state.']);
            }
            $type = $status !== ComplianceCaseInterviewStatus::Scheduled ? $status->value : 'rescheduled';

            return $this->appendEvent($locked, $actor, $before, $after, $type, $data['rationale'], $recordedAt, $events->count() + 1)
                ->load('actor:id,name,email');
        }, 3);
    }

    /** @return array<string,mixed> */
    public static function scheduleRules(): array
    {
        return [
            'subject_user_id' => 'nullable|required_without:subject_reference|integer|exists:users,id',
            'subject_reference' => 'nullable|required_without:subject_user_id|string|max:255',
            'interviewer_id' => 'required|integer|exists:users,id', 'scheduled_at' => 'required|date',
            'location' => 'nullable|string|max:500', 'purpose' => 'required|string|max:30000', 'rationale' => 'required|string|max:30000',
            'compliance_case_id' => 'prohibited', 'status' => 'prohibited', 'conducted_at' => 'prohibited',
            'summary' => 'prohibited', 'cancellation_reason' => 'prohibited', 'version' => 'prohibited',
            'before_snapshot' => 'prohibited', 'after_snapshot' => 'prohibited', 'recorded_by' => 'prohibited',
            'recorded_at' => 'prohibited', 'fingerprint' => 'prohibited',
        ];
    }

    /** @return array<string,mixed> */
    public static function eventRules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(ComplianceCaseInterviewStatus::class)],
            'interviewer_id' => 'sometimes|required|integer|exists:users,id', 'scheduled_at' => 'sometimes|required|date',
            'location' => 'sometimes|nullable|string|max:500', 'purpose' => 'sometimes|required|string|max:30000',
            'conducted_at' => 'sometimes|nullable|date', 'summary' => 'sometimes|nullable|string|max:30000',
            'cancellation_reason' => 'sometimes|nullable|string|max:30000', 'rationale' => 'required|string|max:30000',
            'compliance_case_id' => 'prohibited', 'subject_user_id' => 'prohibited', 'subject_reference' => 'prohibited',
            'version' => 'prohibited', 'event_type' => 'prohibited', 'before_snapshot' => 'prohibited',
            'after_snapshot' => 'prohibited', 'recorded_by' => 'prohibited', 'recorded_at' => 'prohibited', 'fingerprint' => 'prohibited',
        ];
    }

    private function authorizeMutation(User $actor, ComplianceCase $case): void
    {
        abort_unless($actor->can('update', $case), 403);
        app(ComplianceCaseConflictManager::class)->assertClear($actor, $case);
    }

    private function assertInterviewable(ComplianceCase $case): void
    {
        if (! in_array($case->status, [ComplianceCaseStatus::Triaged, ComplianceCaseStatus::Investigating, ComplianceCaseStatus::ActionRequired], true)) {
            throw ValidationException::withMessages(['case' => 'Interviews may be governed only during triage, investigation, or action-required work.']);
        }
    }

    private function lockInterviewer(int $id): User
    {
        $interviewer = User::query()->whereNull('deleted_at')->lockForUpdate()->findOrFail($id);
        if (! $interviewer->can('Investigate Compliance Cases')) {
            throw ValidationException::withMessages(['interviewer_id' => 'The interviewer must be active and hold Investigate Compliance Cases.']);
        }

        return $interviewer;
    }

    /** @return array<string,mixed> */
    private function snapshot(ComplianceCaseInterview $interview): array
    {
        $interview->load(['complianceCase.opener:id,name,email', 'complianceCase.assignee:id,name,email', 'subjectUser:id,name,email', 'interviewer:id,name,email']);

        return $interview->only(['id', 'compliance_case_id', 'status', 'scheduled_at', 'conducted_at', 'location', 'purpose', 'summary', 'cancellation_reason']) + [
            'case' => $interview->complianceCase->only([
                'id', 'number', 'title', 'category', 'priority', 'status', 'allegation', 'source_channel', 'source_reference',
                'reporter_reference', 'confidential', 'due_at', 'triage_summary', 'investigation_summary', 'resolution_summary',
                'closure_summary', 'opened_at', 'resolved_at', 'closed_at', 'governed_at',
            ]) + [
                'opened_by' => $interview->complianceCase->opener?->only(['id', 'name', 'email']),
                'assigned_to' => $interview->complianceCase->assignee?->only(['id', 'name', 'email']),
            ],
            'subject' => $interview->subjectUser?->only(['id', 'name', 'email']),
            'subject_reference' => $interview->subject_reference,
            'interviewer' => $interview->interviewer?->only(['id', 'name', 'email']),
        ];
    }

    /** @param array<string,mixed>|null $before @param array<string,mixed> $after */
    private function appendEvent(ComplianceCaseInterview $interview, User $actor, ?array $before, array $after, string $type, string $rationale, $recordedAt, int $version): ComplianceCaseInterviewEvent
    {
        $payload = [
            'compliance_case_interview_id' => $interview->id, 'version' => $version, 'event_type' => $type,
            'before_snapshot' => $before, 'after_snapshot' => $after, 'rationale' => $rationale,
            'recorded_by' => $actor->id, 'recorded_at' => $recordedAt->toIso8601String(),
        ];

        return $interview->events()->create($payload + ['fingerprint' => hash('sha256', CanonicalJson::encode($payload))]);
    }
}
