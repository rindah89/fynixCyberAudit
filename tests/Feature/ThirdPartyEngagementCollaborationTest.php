<?php

namespace Tests\Feature;

use App\Filament\Resources\ThirdPartyRiskResource\Pages\ViewThirdPartyRisk;
use App\Filament\Resources\ThirdPartyRiskResource\RelationManagers\EngagementsRelationManager;
use App\Filament\Vendor\Resources\CollaborationRequestResource\Pages\ListCollaborationRequests;
use App\Models\ThirdPartyEngagementCollaborationEvent;
use App\Models\ThirdPartyEngagementCollaborationRequest;
use App\Models\ThirdPartyEngagementMonitoringIndicator;
use App\Models\User;
use App\Models\VendorUser;
use App\ThirdPartyRisk\ThirdPartyEngagementCollaborationManager;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ThirdPartyEngagementCollaborationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_manager_vendor_contact_and_independent_reviewer_share_append_only_engagement_request(): void
    {
        $engagement = ThirdPartyEngagementMonitoringIndicator::factory()->create()->engagement;
        $requester = tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo(['Manage Third Party Risk', 'Read Vendors']));
        $contact = VendorUser::factory()->create(['vendor_id' => $engagement->vendor_id]);
        $foreignContact = VendorUser::factory()->create();

        Sanctum::actingAs($requester);
        $requestId = $this->postJson("/api/third-party-engagements/{$engagement->id}/collaboration-requests", [
            'category' => 'assurance', 'subject' => 'Confirm annual assurance schedule',
            'request_text' => 'Provide the planned assurance date and accountable provider contact.',
            'recipient_vendor_user_id' => $contact->id, 'due_at' => today()->addDays(14)->toDateString(),
        ])->assertCreated()->assertJsonPath('data.version', 1)->json('data.id');

        $manager = app(ThirdPartyEngagementCollaborationManager::class);
        try {
            $manager->respond($foreignContact, $manager->findRequest($requestId), ['response_text' => 'Foreign response.']);
            $this->fail('A foreign vendor contact must not respond.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('third_party_engagement_collaboration_events', 1);
    }

    public function test_exact_contact_response_and_independent_acceptance_are_retained_and_scoped(): void
    {
        $engagement = ThirdPartyEngagementMonitoringIndicator::factory()->create()->engagement;
        $requester = tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo(['Manage Third Party Risk', 'Read Vendors']));
        $reviewer = tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo(['Manage Third Party Risk', 'Read Vendors']));
        $contact = VendorUser::factory()->create(['vendor_id' => $engagement->vendor_id]);
        $manager = app(ThirdPartyEngagementCollaborationManager::class);

        $request = $manager->open($requester, $engagement, ['category' => 'assurance', 'subject' => 'Confirm annual assurance schedule',
            'request_text' => 'Provide the planned assurance date and accountable provider contact.', 'recipient_vendor_user_id' => $contact->id,
            'due_at' => today()->addDays(14)->toDateString()]);
        $response = $manager->respond($contact, $request, ['response_text' => 'Assurance is planned for 15 October; the provider security lead is accountable.', 'source_reference' => 'PROVIDER-PLAN-2044']);
        $this->assertSame(2, $response->version);

        Sanctum::actingAs($requester);
        $this->postJson("/api/third-party-engagement-collaboration-requests/{$request->id}/decisions", ['decision' => 'accepted', 'summary' => 'Self acceptance.'])->assertForbidden();
        Sanctum::actingAs($reviewer);
        $this->postJson("/api/third-party-engagement-collaboration-requests/{$request->id}/decisions", ['decision' => 'accepted', 'summary' => 'The provider response addresses the request.'])
            ->assertCreated()->assertJsonPath('data.version', 3)->assertJsonPath('data.status', 'accepted');

        $this->getJson("/api/third-party-engagements/{$engagement->id}/collaboration-requests?per_page=100")
            ->assertOk()->assertJsonPath('data.0.id', $request->id)->assertJsonCount(3, 'data.0.events');
    }

    public function test_staff_and_exact_recipient_have_scoped_operator_surfaces(): void
    {
        $engagement = ThirdPartyEngagementMonitoringIndicator::factory()->create()->engagement;
        $requester = tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo(['Manage Third Party Risk', 'Read Vendors']));
        $contact = VendorUser::factory()->create(['vendor_id' => $engagement->vendor_id]);
        $otherContact = VendorUser::factory()->create(['vendor_id' => $engagement->vendor_id]);
        $manager = app(ThirdPartyEngagementCollaborationManager::class);
        $request = $manager->open($requester, $engagement, ['category' => 'evidence', 'subject' => 'Provide current evidence',
            'request_text' => 'Supply the current provider evidence reference.', 'recipient_vendor_user_id' => $contact->id,
            'due_at' => today()->addWeek()->toDateString()]);

        $this->actingAs($requester, 'web');
        Livewire::test(EngagementsRelationManager::class, ['ownerRecord' => $engagement->vendor, 'pageClass' => ViewThirdPartyRisk::class])
            ->assertCanSeeTableRecords([$engagement])->assertTableActionVisible('open_collaboration', $engagement);

        Filament::setCurrentPanel(Filament::getPanel('vendor'));
        $this->actingAs($contact, 'vendor');
        Livewire::test(ListCollaborationRequests::class)->assertCanSeeTableRecords([$request])->assertTableActionVisible('respond', $request);
        $this->actingAs($otherContact, 'vendor');
        Livewire::test(ListCollaborationRequests::class)->assertCanNotSeeTableRecords([$request]);
    }

    public function test_server_fields_immutability_factory_fingerprints_and_retained_migration_are_enforced(): void
    {
        $engagement = ThirdPartyEngagementMonitoringIndicator::factory()->create()->engagement;
        $manager = tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo(['Manage Third Party Risk', 'Read Vendors']));
        $contact = VendorUser::factory()->create(['vendor_id' => $engagement->vendor_id]);
        Sanctum::actingAs($manager);
        $this->postJson("/api/third-party-engagements/{$engagement->id}/collaboration-requests", [
            'category' => 'risk', 'subject' => 'Risk context', 'request_text' => 'Provide risk context.', 'recipient_vendor_user_id' => $contact->id,
            'due_at' => today()->toDateString(), 'version' => 99, 'fingerprint' => str_repeat('a', 64),
        ])->assertUnprocessable();

        $request = ThirdPartyEngagementCollaborationRequest::factory()->create();
        $event = ThirdPartyEngagementCollaborationEvent::factory()->create(['third_party_engagement_collaboration_request_id' => $request->id]);
        $requestPayload = collect($request->getAttributes())->except(['id', 'created_at', 'updated_at', 'fingerprint'])->all();
        foreach (['engagement_snapshot', 'recipient_snapshot'] as $field) {
            $requestPayload[$field] = $request->{$field};
        }
        $requestPayload['category'] = $request->category->value;
        $requestPayload['due_at'] = $request->due_at->toDateString();
        $requestPayload['opened_at'] = $request->opened_at->toIso8601String();
        $this->assertSame($request->fingerprint, hash('sha256', json_encode($requestPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
        $eventPayload = collect($event->getAttributes())->except(['id', 'created_at', 'updated_at', 'fingerprint'])->all();
        foreach (['actor_snapshot', 'request_snapshot'] as $field) {
            $eventPayload[$field] = $event->{$field};
        }
        $eventPayload['status'] = $event->status->value;
        $eventPayload['recorded_at'] = $event->recorded_at->toIso8601String();
        $this->assertSame($event->fingerprint, hash('sha256', json_encode($eventPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
        $this->assertCount(2, ThirdPartyEngagementCollaborationRequest::factory()->count(2)->create());
        $this->assertCount(2, ThirdPartyEngagementCollaborationEvent::factory()->count(2)->create());

        $migration = require database_path('migrations/2026_08_24_810000_create_third_party_engagement_collaboration.php');
        $migration->down();
        $this->assertDatabaseHas('third_party_engagement_collaboration_requests', ['id' => $request->id]);
        $this->assertDatabaseHas('third_party_engagement_collaboration_events', ['id' => $event->id]);

        $this->expectException(\LogicException::class);
        $event->update(['summary' => 'Rewritten history.']);
    }

    public function test_follow_up_exact_history_bounds_terminal_state_and_request_immutability(): void
    {
        $engagement = ThirdPartyEngagementMonitoringIndicator::factory()->create()->engagement;
        $opener = tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo('Manage Third Party Risk'));
        $reviewer = tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo('Manage Third Party Risk'));
        $contact = VendorUser::factory()->create(['vendor_id' => $engagement->vendor_id]);
        $manager = app(ThirdPartyEngagementCollaborationManager::class);
        $data = ['category' => 'resilience', 'subject' => 'Confirm recovery exercise', 'request_text' => 'Provide the current recovery exercise reference.',
            'recipient_vendor_user_id' => $contact->id, 'due_at' => today()->addWeek()->toDateString()];

        $requests = [];
        for ($index = 0; $index < 100; $index++) {
            $requests[] = $manager->open($opener, $engagement, array_replace($data, ['subject' => "Request {$index}"]));
        }
        $this->assertCount(100, $requests);
        try {
            $manager->open($opener, $engagement, array_replace($data, ['subject' => 'Request 101']));
            $this->fail('The 101st collaboration request must fail.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('third_party_engagement_collaboration_requests', 100);
        }

        $request = $requests[0];
        for ($cycle = 0; $cycle < 9; $cycle++) {
            $manager->respond($contact, $request, ['response_text' => "Provider response {$cycle}."]);
            $manager->decide($reviewer, $request, ['decision' => 'follow_up', 'summary' => "Follow-up {$cycle} required."]);
        }
        $twentieth = $manager->respond($contact, $request, ['response_text' => 'Final provider response.']);
        $this->assertSame(20, $twentieth->version);
        try {
            $manager->decide($reviewer, $request, ['decision' => 'accepted', 'summary' => 'Overflow disposition.']);
            $this->fail('The 21st collaboration event must fail.');
        } catch (ValidationException) {
            $this->assertSame(20, $request->events()->count());
        }

        try {
            $request->update(['subject' => 'Rewritten request.']);
            $this->fail('A collaboration request must remain immutable.');
        } catch (\LogicException) {
            $this->assertSame('Request 0', $request->fresh()->subject);
        }

        DB::table('third_party_engagements')->where('id', $engagement->id)->update(['status' => 'exited']);
        $engagement->refresh()->load(['collaborationRequests.events', 'collaborationRequests.latestEvent']);
        Filament::setCurrentPanel(Filament::getPanel('vendor'));
        $this->actingAs($contact, 'vendor');
        Livewire::test(ListCollaborationRequests::class)->assertTableActionHidden('respond', $requests[1]);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        $this->actingAs($reviewer, 'web');
        Livewire::test(EngagementsRelationManager::class, ['ownerRecord' => $engagement->vendor, 'pageClass' => ViewThirdPartyRisk::class])
            ->assertTableActionHidden('decide_collaboration', $engagement);
        try {
            $manager->open($opener, $engagement, array_replace($data, ['subject' => 'Terminal request']));
            $this->fail('Terminal engagements must reject collaboration.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('third_party_engagement_collaboration_requests', 100);
        }
    }
}
