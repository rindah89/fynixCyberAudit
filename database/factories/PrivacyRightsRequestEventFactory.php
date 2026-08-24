<?php

namespace Database\Factories;

use App\Enums\PrivacyRightsRequestStatus;
use App\Models\PrivacyRightsRequest;
use App\Models\PrivacyRightsRequestEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

class PrivacyRightsRequestEventFactory extends Factory
{
    protected $model = PrivacyRightsRequestEvent::class;

    public function definition(): array
    {
        return ['privacy_rights_request_id' => PrivacyRightsRequest::factory(), 'version' => 1, 'from_status' => null,
            'to_status' => PrivacyRightsRequestStatus::Received, 'summary' => 'Privacy rights request recorded through the stated intake channel.',
            'request_snapshot' => [], 'recorded_by' => fn (array $attributes): int => PrivacyRightsRequest::query()->findOrFail($attributes['privacy_rights_request_id'])->opened_by,
            'recorded_at' => now()->startOfSecond(), 'fingerprint' => str_repeat('0', 64)];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (PrivacyRightsRequestEvent $event): void {
            $request = PrivacyRightsRequest::query()->findOrFail($event->privacy_rights_request_id);
            $request->load(['assignee:id,name,email', 'opener:id,name,email']);
            $snapshot = $request->only(['id', 'number', 'request_type', 'status', 'data_subject_name', 'data_subject_email', 'subject_reference', 'request_details', 'intake_channel', 'jurisdiction_reference', 'received_at', 'due_at', 'identity_verification_summary', 'response_summary', 'decision_basis', 'delivery_reference', 'completed_at', 'governed_at'])
                + ['assignee' => $request->assignee?->only(['id', 'name', 'email']), 'opener' => $request->opener?->only(['id', 'name', 'email'])];
            $snapshot = json_decode(json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), true, flags: JSON_THROW_ON_ERROR);
            $at = now()->startOfSecond();
            $payload = ['privacy_rights_request_id' => $request->id, 'version' => $event->version, 'from_status' => $event->from_status?->value, 'to_status' => $event->to_status->value,
                'summary' => $event->summary, 'request_snapshot' => $snapshot, 'recorded_by' => $event->recorded_by, 'recorded_at' => $at->toIso8601String()];
            $event->request_snapshot = $snapshot;
            $event->recorded_at = $at;
            $event->fingerprint = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        });
    }
}
