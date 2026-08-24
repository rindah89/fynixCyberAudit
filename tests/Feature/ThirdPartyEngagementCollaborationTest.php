<?php

namespace Tests\Feature;

use App\Filament\Resources\ThirdPartyRiskResource\Pages\ViewThirdPartyRisk;
use App\Filament\Resources\ThirdPartyRiskResource\RelationManagers\EngagementsRelationManager;
use App\Filament\Vendor\Resources\CollaborationRequestResource;
use App\Filament\Vendor\Resources\CollaborationRequestResource\Pages\ListCollaborationRequests;
use App\Filament\Vendor\Resources\CollaborationRequestResource\Pages\ViewCollaborationRequest;
use App\Models\ThirdPartyEngagementCollaborationEscalation;
use App\Models\ThirdPartyEngagementCollaborationEscalationAction;
use App\Models\ThirdPartyEngagementCollaborationEvent;
use App\Models\ThirdPartyEngagementCollaborationReminder;
use App\Models\ThirdPartyEngagementCollaborationRequest;
use App\Models\ThirdPartyEngagementMonitoringIndicator;
use App\Models\User;
use App\Models\VendorDocument;
use App\Models\VendorUser;
use App\ThirdPartyRisk\ThirdPartyEngagementCollaborationEscalationManager;
use App\ThirdPartyRisk\ThirdPartyEngagementCollaborationManager;
use App\ThirdPartyRisk\ThirdPartyEngagementCollaborationReminderManager;
use App\ThirdPartyRisk\ThirdPartyEngagementCollaborationResolutionManager;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
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
        foreach (['actor_snapshot', 'request_snapshot', 'evidence_manifest'] as $field) {
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

    public function test_provider_response_retains_bounded_document_bytes_with_dual_acl_and_atomic_rejection(): void
    {
        Storage::fake('private');
        $engagement = ThirdPartyEngagementMonitoringIndicator::factory()->create()->engagement;
        $opener = tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo(['Manage Third Party Risk', 'Read Vendors']));
        $contact = VendorUser::factory()->create(['vendor_id' => $engagement->vendor_id]);
        $foreign = VendorUser::factory()->create();
        $document = VendorDocument::factory()->create(['vendor_id' => $engagement->vendor_id, 'uploaded_by' => $contact->id, 'document_type' => 'other',
            'name' => 'Provider evidence', 'file_path' => 'vendor-documents/provider.txt', 'file_name' => 'provider.txt', 'file_size' => 17,
            'mime_type' => 'text/plain', 'status' => 'pending']);
        $foreignDocument = VendorDocument::factory()->create(['vendor_id' => $foreign->vendor_id, 'uploaded_by' => $foreign->id, 'document_type' => 'other',
            'name' => 'Foreign evidence', 'file_path' => 'vendor-documents/foreign.txt', 'file_name' => 'foreign.txt', 'file_size' => 7,
            'mime_type' => 'text/plain', 'status' => 'pending']);
        Storage::disk('private')->put($document->file_path, 'retained evidence');
        Storage::disk('private')->put($foreignDocument->file_path, 'foreign');
        $manager = app(ThirdPartyEngagementCollaborationManager::class);
        $request = $manager->open($opener, $engagement, ['category' => 'evidence', 'subject' => 'Evidence request', 'request_text' => 'Provide evidence.',
            'recipient_vendor_user_id' => $contact->id, 'due_at' => today()->addWeek()->toDateString()]);

        try {
            $manager->respond($contact, $request, ['response_text' => 'Mixed response.', 'vendor_document_ids' => [$document->id, $foreignDocument->id]]);
            $this->fail('Mixed-vendor evidence must fail atomically.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('third_party_engagement_collaboration_evidence', 0);
            $this->assertSame(1, $request->events()->count());
        }

        $response = $manager->respond($contact, $request, ['response_text' => 'Evidence supplied.', 'vendor_document_ids' => [$document->id]]);
        $evidence = $response->evidence()->firstOrFail();
        $this->assertSame(hash('sha256', 'retained evidence'), $evidence->sha256);
        Storage::disk('private')->put($document->file_path, 'replacement');
        $this->actingAs($opener, 'web')->get(route('third-party-collaboration-evidence.download', $evidence))->assertOk()->assertStreamedContent('retained evidence');
        $this->actingAs($contact, 'vendor')->get(route('vendor.third-party-collaboration-evidence.download', $evidence))->assertOk()->assertStreamedContent('retained evidence');
        $this->actingAs($foreign, 'vendor')->get(route('vendor.third-party-collaboration-evidence.download', $evidence))->assertForbidden();
        $document->delete();
        $this->actingAs($contact, 'vendor');
        $portalRequest = CollaborationRequestResource::getEloquentQuery()->findOrFail($request->id);
        $this->assertCount(0, $portalRequest->events->last()->evidence);
        $this->get(route('vendor.third-party-collaboration-evidence.download', $evidence))->assertForbidden();
        $restrictedStaff = tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo('Manage Third Party Risk'));
        Sanctum::actingAs($restrictedStaff);
        $this->getJson("/api/third-party-engagements/{$engagement->id}/collaboration-requests")
            ->assertOk()->assertJsonCount(0, 'data.0.events.1.evidence')->assertJsonMissing(['file_name_snapshot' => 'provider.txt']);
        $this->actingAs($restrictedStaff, 'web')->get(route('third-party-collaboration-evidence.download', $evidence))->assertForbidden();

        $migration = require database_path('migrations/2026_08_24_820000_create_third_party_collaboration_evidence.php');
        $migration->down();
        $this->assertDatabaseHas('third_party_engagement_collaboration_evidence', ['id' => $evidence->id]);
    }

    public function test_due_and_overdue_collaboration_reminders_are_exact_recipient_idempotent_evidence(): void
    {
        Carbon::setTestNow('2026-08-24 08:00:00');
        $engagement = ThirdPartyEngagementMonitoringIndicator::factory()->create()->engagement;
        $opener = tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo(['Manage Third Party Risk', 'Read Vendors']));
        $contact = VendorUser::factory()->create(['vendor_id' => $engagement->vendor_id]);
        $request = app(ThirdPartyEngagementCollaborationManager::class)->open($opener, $engagement, [
            'category' => 'evidence', 'subject' => 'Provide renewal evidence', 'request_text' => 'Upload the current evidence.',
            'recipient_vendor_user_id' => $contact->id, 'due_at' => '2026-08-27',
        ]);
        $contact->forceFill(['email_verified_at' => null])->save();
        $reminders = app(ThirdPartyEngagementCollaborationReminderManager::class);

        Event::listen(NotificationSending::class, fn (): bool => false);
        try {
            $reminders->reconcile(now());
            $this->fail('A cancelled reminder must not be represented as delivered.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('not accepted', $exception->getMessage());
        }
        $this->assertDatabaseCount('third_party_engagement_collaboration_reminders', 0);
        $this->assertDatabaseCount('notifications', 0);
        Event::forget(NotificationSending::class);
        $this->assertSame(1, $reminders->reconcile(now()));
        $this->assertSame(0, $reminders->reconcile(now()));
        Carbon::setTestNow('2026-08-27 12:00:00');
        $this->assertSame(0, $reminders->reconcile(now()));
        Carbon::setTestNow('2026-08-28 00:00:01');
        $this->assertSame(1, $reminders->reconcile(now()));
        $this->assertSame(0, $reminders->reconcile(now()));
        $answered = app(ThirdPartyEngagementCollaborationManager::class)->open($opener, $engagement, [
            'category' => 'evidence', 'subject' => 'Already answered', 'request_text' => 'Respond before reconciliation.',
            'recipient_vendor_user_id' => $contact->id, 'due_at' => '2026-08-28',
        ]);
        app(ThirdPartyEngagementCollaborationManager::class)->respond($contact, $answered, ['response_text' => 'Answered.']);
        $this->assertSame(0, $reminders->reconcile(now()));
        $this->assertDatabaseMissing('third_party_engagement_collaboration_reminders', ['third_party_engagement_collaboration_request_id' => $answered->id]);

        $request->refresh()->load('reminders');
        $this->assertSame(['due_soon', 'overdue'], $request->reminders->pluck('type')->map->value->all());
        $this->assertDatabaseCount('notifications', 2);
        foreach ($request->reminders as $reminder) {
            $payload = $reminder->only(['third_party_engagement_collaboration_request_id', 'third_party_engagement_id', 'vendor_user_id', 'type', 'channel', 'notification_id', 'recipient_snapshot', 'request_snapshot', 'event_snapshot']);
            $payload['type'] = $reminder->type->value;
            $payload['attempted_at'] = $reminder->attempted_at->toIso8601String();
            $payload['delivered_at'] = $reminder->delivered_at->toIso8601String();
            $this->assertSame(hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), $reminder->fingerprint);
        }

        Sanctum::actingAs($opener);
        $this->getJson("/api/third-party-engagements/{$engagement->id}/collaboration-requests")
            ->assertOk()->assertJsonCount(2, 'data.1.reminders')->assertJsonPath('data.1.reminders.1.type', 'overdue');
        Filament::setCurrentPanel(Filament::getPanel('vendor'));
        $this->actingAs($contact, 'vendor');
        Livewire::test(ListCollaborationRequests::class)->assertSee('Overdue');
        Livewire::test(ViewCollaborationRequest::class, ['record' => $request->id])
            ->assertSee($request->reminders->last()->notification_id)
            ->assertSee('Event snapshot');

        $this->assertThrows(fn () => ThirdPartyEngagementCollaborationReminder::query()->firstOrFail()->update(['channel' => 'mail']), \LogicException::class);
        DB::table('notifications')->where('id', $request->reminders->first()->notification_id)->delete();
        $this->assertDatabaseHas('third_party_engagement_collaboration_reminders', ['id' => $request->reminders->first()->id]);
        $migration = require database_path('migrations/2026_08_24_830000_create_third_party_collaboration_reminders.php');
        $migration->down();
        $this->assertDatabaseHas('third_party_engagement_collaboration_reminders', ['id' => $request->reminders->last()->id]);
        Carbon::setTestNow();
    }

    public function test_persistently_overdue_request_escalates_once_to_current_internal_accountability(): void
    {
        Carbon::setTestNow('2026-08-24 08:00:00');
        $engagement = ThirdPartyEngagementMonitoringIndicator::factory()->create()->engagement;
        $opener = tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo(['Manage Third Party Risk', 'Read Vendors']));
        $contact = VendorUser::factory()->create(['vendor_id' => $engagement->vendor_id]);
        $request = app(ThirdPartyEngagementCollaborationManager::class)->open($opener, $engagement, [
            'category' => 'risk', 'subject' => 'Resolve overdue risk response', 'request_text' => 'Provide the outstanding response.',
            'recipient_vendor_user_id' => $contact->id, 'due_at' => '2026-08-27',
        ]);
        $reminders = app(ThirdPartyEngagementCollaborationReminderManager::class);
        $this->assertSame(1, $reminders->reconcile(now()));
        Carbon::setTestNow('2026-08-28 00:00:01');
        $this->assertSame(1, $reminders->reconcile(now()));

        $currentBusinessOwner = User::factory()->create();
        $currentVendorManager = User::factory()->create();
        $engagement->update(['business_owner_id' => $currentBusinessOwner->id]);
        $engagement->vendor->update(['vendor_manager_id' => $currentVendorManager->id]);
        $escalations = app(ThirdPartyEngagementCollaborationEscalationManager::class);
        Carbon::setTestNow('2026-08-30 23:59:59');
        $this->assertSame(0, $escalations->reconcile(now()));
        Carbon::setTestNow('2026-08-31 00:00:00');

        Event::listen(NotificationSending::class, fn (): bool => false);
        try {
            $escalations->reconcile(now());
            $this->fail('A cancelled escalation must not be represented as delivered.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('not accepted', $exception->getMessage());
        }
        $this->assertDatabaseCount('third_party_engagement_collaboration_escalations', 0);
        $this->assertDatabaseCount('notifications', 2);
        Event::forget(NotificationSending::class);

        $this->assertSame(1, $escalations->reconcile(now()));
        $this->assertSame(0, $escalations->reconcile(now()));
        $this->artisan('fynix:reconcile-third-party-collaboration-escalations')->assertSuccessful();
        $escalation = $request->escalation()->firstOrFail();
        $this->assertEqualsCanonicalizing([$currentBusinessOwner->id, $currentVendorManager->id], collect($escalation->recipient_snapshots)->pluck('id')->all());
        $this->assertEqualsCanonicalizing(['business_owner', 'vendor_manager'], collect($escalation->recipient_snapshots)->flatMap(fn (array $recipient): array => $recipient['roles'])->all());
        $this->assertCount(2, $escalation->notification_ids);
        $this->assertDatabaseCount('notifications', 4);
        $this->assertArrayHasKey('evidence_manifest', $escalation->event_snapshot);
        $eventFingerprintPayload = collect($escalation->event_snapshot)->except(['id', 'fingerprint'])->all();
        $this->assertSame($escalation->event_snapshot['fingerprint'], hash('sha256', json_encode($eventFingerprintPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
        $payload = $escalation->only(['third_party_engagement_collaboration_request_id', 'third_party_engagement_id', 'vendor_user_id', 'channel', 'notification_ids', 'recipient_snapshots', 'request_snapshot', 'event_snapshot', 'overdue_reminder_snapshot']);
        $payload['attempted_at'] = $escalation->attempted_at->toIso8601String();
        $payload['delivered_at'] = $escalation->delivered_at->toIso8601String();
        $this->assertSame(hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), $escalation->fingerprint);

        Sanctum::actingAs($opener);
        $this->getJson("/api/third-party-engagements/{$engagement->id}/collaboration-requests")
            ->assertOk()->assertJsonPath('data.0.escalation.recipient_snapshots.0.id', $currentBusinessOwner->id);
        Filament::setCurrentPanel(Filament::getPanel('vendor'));
        $this->actingAs($contact, 'vendor');
        Livewire::test(ViewCollaborationRequest::class, ['record' => $request->id])
            ->assertSee('Escalated internally')
            ->assertDontSee($currentBusinessOwner->email)
            ->assertDontSee($currentVendorManager->email);

        $this->assertThrows(fn () => ThirdPartyEngagementCollaborationEscalation::query()->firstOrFail()->delete(), \LogicException::class);
        DB::table('notifications')->where('id', $escalation->notification_ids[0])->delete();
        $this->assertDatabaseHas('third_party_engagement_collaboration_escalations', ['id' => $escalation->id]);
        $migration = require database_path('migrations/2026_08_24_840000_create_third_party_collaboration_escalations.php');
        $migration->down();
        $this->assertDatabaseHas('third_party_engagement_collaboration_escalations', ['id' => $escalation->id]);
        Carbon::setTestNow();
    }

    public function test_internal_accountability_acknowledges_and_independently_resolves_escalation_after_accepted_response(): void
    {
        Carbon::setTestNow('2026-08-24 08:00:00');
        $engagement = ThirdPartyEngagementMonitoringIndicator::factory()->create()->engagement;
        $opener = tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo(['Manage Third Party Risk', 'Read Vendors']));
        $businessOwner = User::factory()->create();
        $vendorManager = User::factory()->create();
        $engagement->update(['business_owner_id' => $businessOwner->id]);
        $engagement->vendor->update(['vendor_manager_id' => $vendorManager->id]);
        $contact = VendorUser::factory()->create(['vendor_id' => $engagement->vendor_id]);
        $request = app(ThirdPartyEngagementCollaborationManager::class)->open($opener, $engagement, [
            'category' => 'assurance', 'subject' => 'Close overdue assurance request', 'request_text' => 'Provide the overdue response.',
            'recipient_vendor_user_id' => $contact->id, 'due_at' => '2026-08-27',
        ]);
        $reminders = app(ThirdPartyEngagementCollaborationReminderManager::class);
        $reminders->reconcile(now());
        Carbon::setTestNow('2026-08-28 00:00:01');
        $reminders->reconcile(now());
        Carbon::setTestNow('2026-08-31 00:00:00');
        app(ThirdPartyEngagementCollaborationEscalationManager::class)->reconcile(now());
        $escalation = $request->escalation()->firstOrFail();
        $resolution = app(ThirdPartyEngagementCollaborationResolutionManager::class);

        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/third-party-engagement-collaboration-escalations/{$escalation->id}/acknowledge", [
            'summary' => 'Unauthorized.', 'action_plan' => 'Probe.', 'target_resolution_at' => '2026-09-05',
        ])->assertForbidden();
        Sanctum::actingAs($businessOwner);
        $this->postJson("/api/third-party-engagement-collaboration-escalations/{$escalation->id}/acknowledge", [
            'summary' => 'Ownership accepted.', 'action_plan' => 'Coordinate the provider response.', 'target_resolution_at' => '2026-09-05',
            'status' => 'resolved', 'actor_snapshot' => ['id' => $businessOwner->id],
        ])->assertUnprocessable();
        $acknowledgement = $this->postJson("/api/third-party-engagement-collaboration-escalations/{$escalation->id}/acknowledge", [
            'summary' => 'Ownership accepted.', 'action_plan' => 'Coordinate the provider response.', 'target_resolution_at' => '2026-09-05',
        ])->assertCreated()->assertJsonPath('data.status', 'acknowledged')->json('data');

        try {
            $resolution->resolve($businessOwner, $escalation, ['summary' => 'Premature resolution.']);
            $this->fail('Resolution requires a later accepted provider response and a separated actor.');
        } catch (\Throwable $exception) {
            $status = $exception instanceof ValidationException ? 422 : (method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : $exception->getCode());
            $this->assertContains($status, [403, 422]);
        }
        Carbon::setTestNow('2026-08-31 00:00:01');
        app(ThirdPartyEngagementCollaborationManager::class)->respond($contact, $request, ['response_text' => 'The requested assurance response.']);
        $reviewer = tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo(['Manage Third Party Risk', 'Read Vendors']));
        app(ThirdPartyEngagementCollaborationManager::class)->decide($reviewer, $request, ['decision' => 'accepted', 'summary' => 'Response accepted.']);
        Sanctum::actingAs($reviewer);
        $this->postJson("/api/third-party-engagement-collaboration-escalations/{$escalation->id}/resolve", [
            'summary' => 'Self-reviewed resolution.',
        ])->assertForbidden();
        $resolved = $resolution->resolve($vendorManager, $escalation, ['summary' => 'Internal escalation resolved after acceptance.']);
        $this->assertSame('resolved', $resolved->status->value);
        $this->assertCount(2, $escalation->actions()->get());
        $this->assertSame($acknowledgement['fingerprint'], $escalation->actions()->first()->fingerprint);
        Sanctum::actingAs($businessOwner);
        $this->getJson("/api/third-party-engagements/{$engagement->id}/collaboration-requests")
            ->assertOk()
            ->assertJsonCount(2, 'data.0.escalation.actions')
            ->assertJsonPath('data.0.escalation.actions.1.status', 'resolved');
        foreach ($escalation->actions()->get() as $action) {
            $payload = $action->only(['third_party_engagement_collaboration_escalation_id', 'version', 'status', 'summary', 'action_plan', 'target_resolution_at', 'actor_id', 'actor_snapshot', 'escalation_snapshot', 'accepted_event_snapshot']);
            $payload['status'] = $action->status->value;
            $payload['target_resolution_at'] = $action->target_resolution_at?->toDateString();
            $payload['recorded_at'] = $action->recorded_at->toIso8601String();
            $this->assertSame(hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), $action->fingerprint);
        }
        $this->assertThrows(fn () => ThirdPartyEngagementCollaborationEscalationAction::query()->firstOrFail()->update(['summary' => 'Rewrite']), \LogicException::class);

        $operatorEvidence = view('filament.third-party-engagement', [
            'engagement' => $engagement->fresh()->load(['collaborationRequests.recipient', 'collaborationRequests.opener', 'collaborationRequests.events.evidence', 'collaborationRequests.reminders', 'collaborationRequests.escalation.actions.actor']),
        ])->render();
        $this->assertStringContainsString('Ownership accepted.', $operatorEvidence);
        $this->assertStringContainsString('Internal escalation resolved after acceptance.', $operatorEvidence);

        Filament::setCurrentPanel(Filament::getPanel('vendor'));
        $this->actingAs($contact, 'vendor');
        Livewire::test(ViewCollaborationRequest::class, ['record' => $request->id])
            ->assertSee('Resolved internally')
            ->assertSee($resolved->fingerprint)
            ->assertDontSee('Ownership accepted.')
            ->assertDontSee($businessOwner->email);
        $migration = require database_path('migrations/2026_08_24_850000_create_third_party_collaboration_escalation_actions.php');
        $migration->down();
        $this->assertDatabaseHas('third_party_engagement_collaboration_escalation_actions', ['id' => $resolved->id]);
        Carbon::setTestNow();
    }
}
