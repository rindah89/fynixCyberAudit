<?php

namespace Database\Factories;

use App\Enums\ThirdPartyCollaborationCategory;
use App\Enums\ThirdPartyEngagementStatus;
use App\Models\ThirdPartyEngagement;
use App\Models\ThirdPartyEngagementCollaborationRequest;
use App\Models\User;
use App\Models\VendorUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Arr;

class ThirdPartyEngagementCollaborationRequestFactory extends Factory
{
    protected $model = ThirdPartyEngagementCollaborationRequest::class;

    public function definition(): array
    {
        $createdEngagement = ThirdPartyEngagement::factory()->create(['status' => ThirdPartyEngagementStatus::Active]);
        $engagement = ThirdPartyEngagement::query()->findOrFail($createdEngagement->getKey());
        $recipient = VendorUser::factory()->create(['vendor_id' => $engagement->vendor_id]);

        return ['third_party_engagement_id' => $engagement->id, 'version' => 1, 'category' => ThirdPartyCollaborationCategory::Assurance,
            'subject' => 'Confirm provider assurance schedule', 'request_text' => 'Provide the planned assurance date and accountable contact.',
            'recipient_vendor_user_id' => $recipient->id, 'due_at' => today()->addDays(14), 'engagement_snapshot' => [], 'recipient_snapshot' => [],
            'opened_by' => User::factory()->afterCreating(fn (User $user) => $user->givePermissionTo('Manage Third Party Risk')),
            'opened_at' => now()->startOfSecond(), 'fingerprint' => str_repeat('0', 64)];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ThirdPartyEngagementCollaborationRequest $request): void {
            $engagement = ThirdPartyEngagement::query()->findOrFail($request->third_party_engagement_id);
            $recipient = VendorUser::query()->findOrFail($request->recipient_vendor_user_id);
            $payload = ['third_party_engagement_id' => $engagement->id, 'version' => $request->version, 'category' => $request->category->value,
                'subject' => $request->subject, 'request_text' => $request->request_text, 'recipient_vendor_user_id' => $recipient->id,
                'due_at' => $request->due_at->toDateString(), 'engagement_snapshot' => Arr::only($engagement->toArray(), ['id', 'vendor_id', 'code', 'name', 'service_description', 'business_owner_id', 'criticality', 'data_access', 'status', 'term_start_at', 'term_end_at', 'next_review_at', 'approval_snapshot', 'onboarding_readiness_snapshot', 'offboarding_readiness_snapshot', 'governed_at']),
                'recipient_snapshot' => Arr::only($recipient->toArray(), ['id', 'vendor_id', 'name', 'email', 'is_primary']), 'opened_by' => $request->opened_by,
                'opened_at' => $request->opened_at->copy()->startOfSecond()->toIso8601String()];
            $request->forceFill($payload + ['fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))]);
        });
    }
}
