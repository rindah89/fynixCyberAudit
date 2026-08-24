<?php

namespace Tests\Feature;

use App\Filament\Resources\PrivacyRightsRequestResource;
use App\Filament\Resources\PrivacyRightsRequestResource\Pages\ViewPrivacyRightsRequest;
use App\Filament\Resources\PrivacyRightsRequestResource\RelationManagers\EventsRelationManager;
use App\Models\PrivacyRightsRequest;
use App\Models\PrivacyRightsRequestEvent;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

class PrivacyRightsRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Config::set('enterprise.modules.privacy_management', true);
    }

    public function test_manager_records_and_handler_fulfills_forward_rights_request_history(): void
    {
        [$manager, $handler] = $this->actors();
        Sanctum::actingAs($manager);
        $id = $this->postJson('/api/privacy-rights-requests', $this->payload($handler))->assertCreated()->assertJsonPath('data.status', 'received')->json('data.id');
        Sanctum::actingAs($handler);
        $this->postJson("/api/privacy-rights-requests/{$id}/events", ['status' => 'identity_verification', 'summary' => 'Begin deliberate identity review.'])->assertOk();
        $this->postJson("/api/privacy-rights-requests/{$id}/events", ['status' => 'in_progress', 'summary' => 'Identity review recorded.', 'identity_verification_summary' => 'The handler reviewed the supplied customer reference and recorded a match.'])->assertOk();
        $this->postJson("/api/privacy-rights-requests/{$id}/events", ['status' => 'fulfilled', 'summary' => 'Response package recorded as delivered.', 'response_summary' => 'Access response package prepared for the declared scope.', 'delivery_reference' => 'PORTAL-DELIVERY-4401'])
            ->assertOk()->assertJsonPath('data.request_snapshot.status', 'fulfilled');
        $request = PrivacyRightsRequest::findOrFail($id);
        $this->assertSame(4, $request->events()->count());
        $this->assertSame('complete', $request->due_state);
        $event = $request->events()->latest('version')->firstOrFail();
        $payload = ['privacy_rights_request_id' => $event->privacy_rights_request_id, 'version' => $event->version, 'from_status' => $event->from_status?->value, 'to_status' => $event->to_status->value, 'summary' => $event->summary, 'request_snapshot' => $event->request_snapshot, 'recorded_by' => $event->recorded_by, 'recorded_at' => $event->recorded_at->toIso8601String()];
        $this->assertSame($event->fingerprint, hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
    }

    public function test_sensitive_scope_server_fields_assignment_and_terminal_rules_are_enforced(): void
    {
        [$manager, $handler] = $this->actors();
        $outsider = User::factory()->create();
        $privacyReader = User::factory()->create();
        $privacyReader->givePermissionTo('Read Privacy');
        Sanctum::actingAs($outsider);
        $this->postJson('/api/privacy-rights-requests', $this->payload($handler))->assertForbidden();
        Sanctum::actingAs($manager);
        $this->postJson('/api/privacy-rights-requests', $this->payload($handler) + ['number' => 'CALLER'])->assertUnprocessable()->assertJsonValidationErrors('number');
        $id = $this->postJson('/api/privacy-rights-requests', $this->payload($handler))->assertCreated()->json('data.id');
        Sanctum::actingAs($outsider);
        $this->getJson('/api/privacy-rights-requests')->assertForbidden();
        $this->getJson("/api/privacy-rights-requests/{$id}")->assertForbidden();
        Sanctum::actingAs($privacyReader);
        $this->getJson("/api/privacy-rights-requests/{$id}")->assertForbidden();
        Sanctum::actingAs($handler);
        $this->getJson('/api/privacy-rights-requests')->assertOk()->assertJsonPath('total', 1);
        $this->postJson("/api/privacy-rights-requests/{$id}/events", ['status' => 'identity_verification', 'summary' => 'probe', 'assigned_to' => $outsider->id])->assertUnprocessable()->assertJsonValidationErrors('assigned_to');
        $this->postJson("/api/privacy-rights-requests/{$id}/events", ['status' => 'fulfilled', 'summary' => 'skip', 'response_summary' => 'skip', 'delivery_reference' => 'skip'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson("/api/privacy-rights-requests/{$id}/events", ['status' => 'identity_verification', 'summary' => 'verify'])->assertOk();
        $this->postJson("/api/privacy-rights-requests/{$id}/events", ['status' => 'in_progress', 'summary' => 'verified'])->assertUnprocessable()->assertJsonValidationErrors('identity_verification_summary');
    }

    public function test_operator_factory_immutability_and_retained_migration_are_coherent(): void
    {
        [$manager, $handler] = $this->actors();
        $request = PrivacyRightsRequest::factory()->create(['assigned_to' => $handler->id, 'opened_by' => $manager->id]);
        $this->assertTrue($request->assignee->can('Handle Privacy Rights'));
        $this->assertTrue($request->opener->can('Manage Privacy Rights'));
        $event = PrivacyRightsRequestEvent::factory()->create(['privacy_rights_request_id' => $request->id, 'recorded_by' => $manager->id]);
        $payload = ['privacy_rights_request_id' => $event->privacy_rights_request_id, 'version' => $event->version, 'from_status' => $event->from_status?->value, 'to_status' => $event->to_status->value, 'summary' => $event->summary, 'request_snapshot' => $event->request_snapshot, 'recorded_by' => $event->recorded_by, 'recorded_at' => $event->recorded_at->toIso8601String()];
        $this->assertSame($event->fingerprint, hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
        try {
            $event->delete();
            $this->fail('Event was deletable.');
        } catch (LogicException) {
            $this->assertDatabaseHas('privacy_rights_request_events', ['id' => $event->id]);
        }
        $migration = require database_path('migrations/2026_08_24_740000_create_privacy_rights_request_history.php');
        $migration->down();
        $this->assertDatabaseHas('privacy_rights_request_events', ['id' => $event->id]);
        $this->actingAs($manager)->get(PrivacyRightsRequestResource::getUrl('index'))->assertOk();
        Livewire::actingAs($manager)->test(EventsRelationManager::class, ['ownerRecord' => $request, 'pageClass' => ViewPrivacyRightsRequest::class])->assertCanSeeTableRecords([$event])->assertTableActionVisible('inspect', $event);
    }

    private function actors(): array
    {
        $manager = User::factory()->create();
        $manager->givePermissionTo('Manage Privacy Rights');
        $handler = User::factory()->create();
        $handler->givePermissionTo('Handle Privacy Rights');

        return [$manager, $handler];
    }

    private function payload(User $handler): array
    {
        return ['request_type' => 'access', 'data_subject_name' => 'Ada Customer', 'data_subject_email' => 'ada@example.test', 'subject_reference' => 'CRM-4401',
            'request_details' => 'Provide the personal data associated with the declared customer account.', 'intake_channel' => 'Authenticated support handoff',
            'jurisdiction_reference' => 'Operator-selected privacy program reference', 'received_at' => now()->subHour()->toIso8601String(), 'due_at' => now()->addDays(30)->toIso8601String(), 'assigned_to' => $handler->id];
    }
}
