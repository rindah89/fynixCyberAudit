<?php

namespace Database\Factories;

use App\Enums\ThirdPartyCollaborationStatus;
use App\Models\ThirdPartyEngagementCollaborationEvent;
use App\Models\ThirdPartyEngagementCollaborationRequest;
use App\Models\User;
use App\Models\VendorUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Arr;

class ThirdPartyEngagementCollaborationEventFactory extends Factory
{
    protected $model = ThirdPartyEngagementCollaborationEvent::class;

    public function definition(): array
    {
        return ['third_party_engagement_collaboration_request_id' => ThirdPartyEngagementCollaborationRequest::factory(), 'version' => 1,
            'status' => ThirdPartyCollaborationStatus::Requested, 'response_text' => null, 'source_reference' => null,
            'summary' => 'Collaboration request opened.', 'actor_type' => 'user', 'actor_id' => 1, 'actor_snapshot' => [],
            'request_snapshot' => [], 'evidence_manifest' => [], 'recorded_at' => now()->startOfSecond(), 'fingerprint' => str_repeat('0', 64)];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ThirdPartyEngagementCollaborationEvent $event): void {
            $request = ThirdPartyEngagementCollaborationRequest::query()->findOrFail($event->third_party_engagement_collaboration_request_id);
            $status = $event->status;
            $actor = $status === ThirdPartyCollaborationStatus::Responded
                ? VendorUser::withTrashed()->findOrFail($request->recipient_vendor_user_id)
                : ($status === ThirdPartyCollaborationStatus::Requested
                    ? User::withTrashed()->findOrFail($request->opened_by)
                    : tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo('Manage Third Party Risk')));
            $actorType = $actor instanceof VendorUser ? 'vendor_user' : 'user';
            $actorSnapshot = $actor instanceof VendorUser
                ? Arr::only($actor->toArray(), ['id', 'vendor_id', 'name', 'email', 'is_primary'])
                : Arr::only($actor->toArray(), ['id', 'name', 'email']);
            $responseText = $status === ThirdPartyCollaborationStatus::Responded ? ($event->response_text ?: 'Factory provider response.') : null;
            $summary = in_array($status, [ThirdPartyCollaborationStatus::Accepted, ThirdPartyCollaborationStatus::FollowUp], true)
                ? ($event->summary ?: 'Factory staff disposition.')
                : ($status === ThirdPartyCollaborationStatus::Requested ? ($event->summary ?: 'Collaboration request opened.') : null);
            $payload = ['third_party_engagement_collaboration_request_id' => $request->id, 'version' => $event->version,
                'status' => $event->status->value, 'response_text' => $responseText, 'source_reference' => $event->source_reference,
                'summary' => $summary, 'actor_type' => $actorType, 'actor_id' => $actor->id,
                'actor_snapshot' => $actorSnapshot,
                'request_snapshot' => Arr::only($request->attributesToArray(), ['id', 'third_party_engagement_id', 'version', 'category', 'subject', 'request_text', 'recipient_vendor_user_id', 'due_at', 'engagement_snapshot', 'recipient_snapshot', 'opened_by', 'opened_at', 'fingerprint']),
                'evidence_manifest' => $event->evidence_manifest ?? [],
                'recorded_at' => $event->recorded_at->copy()->startOfSecond()->toIso8601String()];
            $event->forceFill($payload + ['fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))]);
        });
    }
}
