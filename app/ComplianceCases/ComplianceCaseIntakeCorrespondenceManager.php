<?php

namespace App\ComplianceCases;

use App\Enums\ComplianceCaseIntakeAudience;
use App\Models\ComplianceCaseIntake;
use App\Models\ComplianceCaseIntakeDisposition;
use App\Models\ComplianceCaseIntakeMessage;
use App\Models\User;
use App\Support\CanonicalJson;
use App\Support\Enterprise;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ComplianceCaseIntakeCorrespondenceManager
{
    public function record(User $actor, ComplianceCaseIntake $intake, array $data): ComplianceCaseIntakeMessage
    {
        Enterprise::assertEnabled('compliance_cases');

        return DB::transaction(function () use ($actor, $intake, $data): ComplianceCaseIntakeMessage {
            $locked = ComplianceCaseIntake::query()->lockForUpdate()->findOrFail($intake->id);
            $manager = $actor->can('Manage Compliance Cases');
            abort_unless($manager || ($actor->id === $locked->submitted_by && ! $actor->trashed()), 403);
            if (! $manager && ($data['audience'] ?? null) !== ComplianceCaseIntakeAudience::Reporter->value) {
                abort(403);
            }
            if (isset($data['message']) && is_string($data['message'])) {
                $data['message'] = trim($data['message']);
            }
            $data = Validator::make($data, self::rules())->validate();
            $lockedActor = User::query()->whereNull('deleted_at')->lockForUpdate()->findOrFail($actor->id);
            $messages = ComplianceCaseIntakeMessage::query()->where('compliance_case_intake_id', $locked->id)->orderBy('version')->lockForUpdate()->get();
            if ($messages->count() >= 100) {
                throw ValidationException::withMessages(['intake' => 'A compliance case intake may retain at most 100 correspondence messages.']);
            }
            $message = new ComplianceCaseIntakeMessage([
                'compliance_case_intake_id' => $locked->id, 'version' => $messages->count() + 1,
                'audience' => ComplianceCaseIntakeAudience::from($data['audience']), 'message' => $data['message'],
                'actor_id' => $lockedActor->id, 'actor_snapshot' => $lockedActor->only(['id', 'name', 'email']),
                'intake_snapshot' => $this->intakeSnapshot($locked), 'disposition_snapshot' => $this->dispositionSnapshot($locked),
                'recorded_at' => now()->startOfSecond(),
            ]);
            $message->fingerprint = hash('sha256', CanonicalJson::encode($this->payload($message)));
            $message->save();

            return $message->load('actor:id,name,email');
        }, 3);
    }

    public function history(User $actor, ComplianceCaseIntake $intake, int $perPage): LengthAwarePaginator
    {
        Enterprise::assertEnabled('compliance_cases');
        $manager = $actor->can('Manage Compliance Cases');
        $activeActor = User::query()->whereNull('deleted_at')->find($actor->id);
        abort_unless($activeActor !== null && ($manager || $actor->id === $intake->submitted_by), 403);
        $query = $intake->messages()->with(['actor:id,name,email', 'acknowledgement.recipient:id,name,email']);
        if (! $manager) {
            $query->where('audience', ComplianceCaseIntakeAudience::Reporter->value);
        }
        $history = $query->paginate($perPage);
        if (! $manager) {
            $history->setCollection($history->getCollection()->map(fn (ComplianceCaseIntakeMessage $message): array => $this->reporterProjection($message)));
        }

        return $history;
    }

    public function reporterProjection(ComplianceCaseIntakeMessage $message): array
    {
        return ['id' => $message->id, 'version' => $message->version, 'audience' => $message->audience, 'message' => $message->message,
            'actor' => $message->actor?->only(['id', 'name']), 'recorded_at' => $message->recorded_at, 'fingerprint' => $message->fingerprint,
            'acknowledgement' => $message->acknowledgement?->only(['acknowledged_at', 'fingerprint'])];
    }

    public function intakeSnapshot(ComplianceCaseIntake $intake): array
    {
        return ['id' => $intake->id] + app(ComplianceCaseIntakeManager::class)->submissionPayload($intake) + ['fingerprint' => $intake->fingerprint];
    }

    public function dispositionSnapshot(ComplianceCaseIntake $intake): ?array
    {
        $decision = ComplianceCaseIntakeDisposition::query()->where('compliance_case_intake_id', $intake->id)->first();

        return $decision === null ? null : ['id' => $decision->id] + app(ComplianceCaseIntakeManager::class)->decisionPayload($decision) + ['fingerprint' => $decision->fingerprint];
    }

    public function payload(ComplianceCaseIntakeMessage $message): array
    {
        return ['compliance_case_intake_id' => $message->compliance_case_intake_id, 'version' => $message->version,
            'audience' => $message->audience instanceof \BackedEnum ? $message->audience->value : $message->audience,
            'message' => $message->message, 'actor_id' => $message->actor_id, 'actor_snapshot' => $message->actor_snapshot,
            'intake_snapshot' => $message->intake_snapshot, 'disposition_snapshot' => $message->disposition_snapshot,
            'recorded_at' => $message->recorded_at?->toIso8601String()];
    }

    public static function rules(): array
    {
        return ['audience' => ['required', Rule::enum(ComplianceCaseIntakeAudience::class)], 'message' => 'required|string|max:30000',
            'id' => 'prohibited', 'compliance_case_intake_id' => 'prohibited', 'version' => 'prohibited', 'actor_id' => 'prohibited',
            'actor_snapshot' => 'prohibited', 'intake_snapshot' => 'prohibited', 'disposition_snapshot' => 'prohibited',
            'recorded_at' => 'prohibited', 'fingerprint' => 'prohibited', 'created_at' => 'prohibited', 'updated_at' => 'prohibited'];
    }
}
