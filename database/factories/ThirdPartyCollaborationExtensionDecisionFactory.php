<?php

namespace Database\Factories;

use App\Enums\ThirdPartyCollaborationExtensionDecision as Decision;
use App\Models\ThirdPartyCollaborationExtension;
use App\Models\ThirdPartyCollaborationExtensionDecision;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ThirdPartyCollaborationExtensionDecisionFactory extends Factory
{
    protected $model = ThirdPartyCollaborationExtensionDecision::class;

    public function definition(): array
    {
        $extension = ThirdPartyCollaborationExtension::factory()->create();
        $decider = tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo('Manage Third Party Risk'));

        return [
            'third_party_collaboration_extension_id' => $extension->id,
            'decision' => Decision::Approved,
            'summary' => 'The extension is approved against the retained request context.',
            'decided_by' => $decider->id,
            'decider_snapshot' => $decider->only(['id', 'name', 'email']),
            'extension_snapshot' => $extension->attributesToArray(),
            'decided_at' => now()->startOfSecond(),
            'fingerprint' => str_repeat('0', 64),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ThirdPartyCollaborationExtensionDecision $decision): void {
            $payload = $decision->only(['third_party_collaboration_extension_id', 'decision', 'summary', 'decided_by', 'decider_snapshot', 'extension_snapshot', 'decided_at']);
            $payload['decision'] = $decision->decision->value;
            $payload['decided_at'] = $decision->decided_at->toIso8601String();
            $decision->fingerprint = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        });
    }
}
