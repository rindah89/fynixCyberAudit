<?php

namespace App\ComplianceCases;

use App\Enums\ComplianceCaseIntakeAudience;
use App\Models\ComplianceCaseIntake;
use App\Models\ComplianceCaseIntakeMessage;
use App\Models\ComplianceCaseIntakeMessageAcknowledgement;
use App\Models\User;
use App\Support\CanonicalJson;
use App\Support\Enterprise;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ComplianceCaseIntakeMessageAcknowledgementManager
{
    public function acknowledge(User $actor, ComplianceCaseIntakeMessage $message): ComplianceCaseIntakeMessageAcknowledgement
    {
        Enterprise::assertEnabled('compliance_cases');

        return DB::transaction(function () use ($actor, $message): ComplianceCaseIntakeMessageAcknowledgement {
            $intakeId = ComplianceCaseIntakeMessage::query()->whereKey($message->id)->value('compliance_case_intake_id');
            $intake = ComplianceCaseIntake::query()->lockForUpdate()->findOrFail($intakeId);
            $locked = ComplianceCaseIntakeMessage::query()->where('compliance_case_intake_id', $intake->id)->lockForUpdate()->findOrFail($message->id);
            abort_unless($actor->id === $intake->submitted_by, 403);
            abort_unless($locked->audience === ComplianceCaseIntakeAudience::Reporter, 403);
            $recipient = User::query()->whereNull('deleted_at')->lockForUpdate()->findOrFail($actor->id);
            if ($locked->actor_id === $recipient->id) {
                throw ValidationException::withMessages(['message' => 'A reporter cannot acknowledge their own message.']);
            }
            if (ComplianceCaseIntakeMessageAcknowledgement::query()->where('compliance_case_intake_message_id', $locked->id)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['message' => 'This message has already been acknowledged.']);
            }
            $acknowledgement = new ComplianceCaseIntakeMessageAcknowledgement([
                'compliance_case_intake_message_id' => $locked->id, 'recipient_id' => $recipient->id,
                'recipient_snapshot' => $recipient->only(['id', 'name', 'email']),
                'message_snapshot' => $this->messageSnapshot($locked), 'acknowledged_at' => now()->startOfSecond(),
            ]);
            $acknowledgement->fingerprint = hash('sha256', CanonicalJson::encode($this->payload($acknowledgement)));
            $acknowledgement->save();

            return $acknowledgement->load('recipient:id,name,email');
        }, 3);
    }

    public function messageSnapshot(ComplianceCaseIntakeMessage $message): array
    {
        return ['id' => $message->id] + app(ComplianceCaseIntakeCorrespondenceManager::class)->payload($message) + ['fingerprint' => $message->fingerprint];
    }

    public function payload(ComplianceCaseIntakeMessageAcknowledgement $acknowledgement): array
    {
        return ['compliance_case_intake_message_id' => $acknowledgement->compliance_case_intake_message_id,
            'recipient_id' => $acknowledgement->recipient_id, 'recipient_snapshot' => $acknowledgement->recipient_snapshot,
            'message_snapshot' => $acknowledgement->message_snapshot,
            'acknowledged_at' => $acknowledgement->acknowledged_at?->toIso8601String()];
    }
}
