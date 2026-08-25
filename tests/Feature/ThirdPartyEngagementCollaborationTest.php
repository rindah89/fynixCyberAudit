<?php

namespace Tests\Feature;

use App\Filament\Resources\ThirdPartyRiskResource\Pages\ViewThirdPartyRisk;
use App\Filament\Resources\ThirdPartyRiskResource\RelationManagers\EngagementsRelationManager;
use App\Filament\Vendor\Resources\CollaborationRequestResource;
use App\Filament\Vendor\Resources\CollaborationRequestResource\Pages\ListCollaborationRequests;
use App\Filament\Vendor\Resources\CollaborationRequestResource\Pages\ViewCollaborationRequest;
use App\Models\ThirdPartyCollaborationClosureDelivery;
use App\Models\ThirdPartyCollaborationEscalationIssue;
use App\Models\ThirdPartyCollaborationExtension;
use App\Models\ThirdPartyCollaborationExtensionDecision;
use App\Models\ThirdPartyCollaborationRecipientReassignment;
use App\Models\ThirdPartyCollaborationRequestAcknowledgement;
use App\Models\ThirdPartyCollaborationRequestCancellation;
use App\Models\ThirdPartyCollaborationRequestClosure;
use App\Models\ThirdPartyEngagementCollaborationEscalation;
use App\Models\ThirdPartyEngagementCollaborationEscalationAction;
use App\Models\ThirdPartyEngagementCollaborationEvent;
use App\Models\ThirdPartyEngagementCollaborationReminder;
use App\Models\ThirdPartyEngagementCollaborationRequest;
use App\Models\ThirdPartyEngagementMonitoringIndicator;
use App\Models\User;
use App\Models\VendorDocument;
use App\Models\VendorUser;
use App\Support\CanonicalJson;
use App\ThirdPartyRisk\ThirdPartyEngagementCollaborationAcknowledgementManager;
use App\ThirdPartyRisk\ThirdPartyEngagementCollaborationCancellationManager;
use App\ThirdPartyRisk\ThirdPartyEngagementCollaborationClosureManager;
use App\ThirdPartyRisk\ThirdPartyEngagementCollaborationEscalationManager;
use App\ThirdPartyRisk\ThirdPartyEngagementCollaborationExtensionManager;
use App\ThirdPartyRisk\ThirdPartyEngagementCollaborationManager;
use App\ThirdPartyRisk\ThirdPartyEngagementCollaborationRecipientManager;
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
            $payload = $reminder->only(['third_party_engagement_collaboration_request_id', 'third_party_engagement_id', 'vendor_user_id', 'type', 'due_context_fingerprint', 'effective_due_at', 'due_context_snapshot', 'channel', 'notification_id', 'recipient_snapshot', 'request_snapshot', 'event_snapshot']);
            $payload['type'] = $reminder->type->value;
            $payload['effective_due_at'] = $reminder->effective_due_at->toDateString();
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
        $payload = $escalation->only(['third_party_engagement_collaboration_request_id', 'third_party_engagement_id', 'vendor_user_id', 'effective_due_at', 'due_context_snapshot', 'channel', 'notification_ids', 'recipient_snapshots', 'request_snapshot', 'event_snapshot', 'overdue_reminder_snapshot']);
        $payload['effective_due_at'] = $escalation->effective_due_at->toDateString();
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
        $closureActor = tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo(['Manage Third Party Risk', 'Read Vendors']));
        try {
            app(ThirdPartyEngagementCollaborationClosureManager::class)->close($closureActor, $request, ['summary' => 'Closure before escalation resolution.']);
            $this->fail('An unresolved escalation must prevent request closure.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('request', $exception->errors());
        }
        Sanctum::actingAs($reviewer);
        $this->postJson("/api/third-party-engagement-collaboration-escalations/{$escalation->id}/resolve", [
            'summary' => 'Self-reviewed resolution.',
        ])->assertForbidden();
        $resolved = $resolution->resolve($vendorManager, $escalation, ['summary' => 'Internal escalation resolved after acceptance.']);
        $this->assertSame('resolved', $resolved->status->value);
        $closure = app(ThirdPartyEngagementCollaborationClosureManager::class)->close($closureActor, $request, ['summary' => 'Accepted request closed after escalation resolution.']);
        $this->assertSame($resolved->fingerprint, data_get($closure->escalation_snapshot, 'latest_action.fingerprint'));
        $this->assertSame($escalation->fingerprint, data_get($closure->escalation_snapshot, 'escalation.fingerprint'));
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

    public function test_missed_internal_target_is_handed_to_one_retained_governance_issue_without_implying_remediation(): void
    {
        Carbon::setTestNow('2026-08-24 08:00:00');
        $engagement = ThirdPartyEngagementMonitoringIndicator::factory()->create()->engagement;
        $manager = tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo(['Manage Third Party Risk', 'Read Vendors']));
        $businessOwner = User::factory()->create();
        $vendorManager = User::factory()->create();
        $engagement->update(['business_owner_id' => $businessOwner->id]);
        $engagement->vendor->update(['vendor_manager_id' => $vendorManager->id]);
        $contact = VendorUser::factory()->create(['vendor_id' => $engagement->vendor_id]);
        $request = app(ThirdPartyEngagementCollaborationManager::class)->open($manager, $engagement, [
            'category' => 'assurance', 'subject' => 'Missed internal target', 'request_text' => 'Provide the overdue response.',
            'recipient_vendor_user_id' => $contact->id, 'due_at' => '2026-08-27',
        ]);
        $reminders = app(ThirdPartyEngagementCollaborationReminderManager::class);
        $reminders->reconcile(now());
        Carbon::setTestNow('2026-08-28 00:00:01');
        $reminders->reconcile(now());
        Carbon::setTestNow('2026-08-31 00:00:00');
        app(ThirdPartyEngagementCollaborationEscalationManager::class)->reconcile(now());
        $escalation = $request->escalation()->firstOrFail();
        app(ThirdPartyEngagementCollaborationResolutionManager::class)->acknowledge($businessOwner, $escalation, [
            'summary' => 'Ownership accepted.', 'action_plan' => 'Secure an acceptable response.', 'target_resolution_at' => '2026-08-31',
        ]);

        Sanctum::actingAs($manager);
        $this->postJson("/api/third-party-engagement-collaboration-escalations/{$escalation->id}/issues", [
            'rationale' => 'Caller-owned evidence.', 'owner_id' => $manager->id,
        ])->assertUnprocessable();
        $this->postJson("/api/third-party-engagement-collaboration-escalations/{$escalation->id}/issues", [
            'rationale' => 'Target has not ended.',
        ])->assertUnprocessable();
        Carbon::setTestNow('2026-09-01 00:00:00');
        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/third-party-engagement-collaboration-escalations/{$escalation->id}/issues", [
            'rationale' => 'Unauthorized probe.',
        ])->assertForbidden();
        Filament::setCurrentPanel(Filament::getPanel('app'));
        $this->actingAs($manager, 'web');
        Livewire::test(EngagementsRelationManager::class, ['ownerRecord' => $engagement->vendor, 'pageClass' => ViewThirdPartyRisk::class])
            ->assertTableActionVisible('open_collaboration_issue', $engagement);
        Sanctum::actingAs($manager);
        $first = $this->postJson("/api/third-party-engagement-collaboration-escalations/{$escalation->id}/issues", [
            'rationale' => 'The committed internal target elapsed without an accepted response.',
        ])->assertCreated()->assertJsonPath('data.status', 'open')->assertJsonPath('data.lifecycle.status', 'open')->json('data');
        $second = $this->postJson("/api/third-party-engagement-collaboration-escalations/{$escalation->id}/issues", [
            'rationale' => 'Idempotent retry.',
        ])->assertOk()->json('data');
        $this->assertSame($first['id'], $second['id']);
        $this->assertDatabaseCount('third_party_collaboration_escalation_issues', 1);
        $this->assertDatabaseHas('governance_issue_lifecycles', ['issue_id' => $first['id'], 'status' => 'open']);
        $issue = ThirdPartyCollaborationEscalationIssue::query()->findOrFail($first['id']);
        $payload = $issue->only(['third_party_engagement_collaboration_escalation_id', 'third_party_engagement_collaboration_escalation_action_id', 'third_party_engagement_id', 'owner_id', 'opened_by', 'title', 'description', 'severity', 'status', 'source_snapshot']);
        $payload['status'] = $issue->status->value;
        $payload['opened_at'] = $issue->opened_at->toIso8601String();
        $this->assertSame(hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), $issue->fingerprint);
        $this->assertThrows(fn () => $issue->update(['title' => 'Rewritten issue']), \LogicException::class);
        $this->assertThrows(fn () => $issue->delete(), \LogicException::class);
        $this->getJson("/api/third-party-engagements/{$engagement->id}/collaboration-requests")
            ->assertOk()->assertJsonPath('data.0.escalation.issue.lifecycle.status', 'open');
        Sanctum::actingAs($businessOwner);
        $this->getJson("/api/governance-issues/third_party_collaboration/{$issue->id}")
            ->assertOk()->assertJsonPath('data.issue.fingerprint', $issue->fingerprint);
        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/governance-issues/third_party_collaboration/{$issue->id}")->assertForbidden();
        Filament::setCurrentPanel(Filament::getPanel('app'));
        $this->actingAs($manager, 'web');
        Livewire::test(EngagementsRelationManager::class, ['ownerRecord' => $engagement->vendor, 'pageClass' => ViewThirdPartyRisk::class])
            ->assertTableActionHidden('open_collaboration_issue', $engagement);

        Filament::setCurrentPanel(Filament::getPanel('vendor'));
        $this->actingAs($contact, 'vendor');
        Livewire::test(ViewCollaborationRequest::class, ['record' => $request->id])
            ->assertDontSee('The committed internal target elapsed')
            ->assertDontSee($issue->fingerprint);

        app(ThirdPartyEngagementCollaborationManager::class)->respond($contact, $request, ['response_text' => 'Late provider response.']);
        $reviewer = tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo('Manage Third Party Risk'));
        app(ThirdPartyEngagementCollaborationManager::class)->decide($reviewer, $request, ['decision' => 'accepted', 'summary' => 'Late response accepted.']);
        app(ThirdPartyEngagementCollaborationResolutionManager::class)->resolve($vendorManager, $escalation, ['summary' => 'Internal escalation resolved.']);
        $this->assertDatabaseHas('governance_issue_lifecycles', ['issue_id' => $first['id'], 'status' => 'open']);
        Sanctum::actingAs($manager);
        $this->postJson("/api/third-party-engagement-collaboration-escalations/{$escalation->id}/issues", [
            'rationale' => 'Retry after response resolution.',
        ])->assertOk()->assertJsonPath('data.id', $first['id']);
        $migration = require database_path('migrations/2026_08_24_860000_create_third_party_collaboration_escalation_issues.php');
        $migration->down();
        $this->assertDatabaseHas('third_party_collaboration_escalation_issues', ['id' => $first['id']]);
        Carbon::setTestNow();
    }

    public function test_provider_requests_and_independent_staff_decides_due_extensions_that_drive_reminder_and_escalation_timing(): void
    {
        Carbon::setTestNow('2026-08-24 08:00:00');
        $engagement = ThirdPartyEngagementMonitoringIndicator::factory()->create()->engagement;
        DB::table('third_party_engagements')->where('id', $engagement->id)->update(['term_end_at' => '2026-10-01']);
        $engagement->refresh();
        $opener = tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo(['Manage Third Party Risk', 'Read Vendors']));
        $reviewer = tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo(['Manage Third Party Risk', 'Read Vendors']));
        $contact = VendorUser::factory()->create(['vendor_id' => $engagement->vendor_id]);
        $otherContact = VendorUser::factory()->create(['vendor_id' => $engagement->vendor_id]);
        $request = app(ThirdPartyEngagementCollaborationManager::class)->open($opener, $engagement, [
            'category' => 'assurance', 'subject' => 'Extension-governed request', 'request_text' => 'Provide the assurance response.',
            'recipient_vendor_user_id' => $contact->id, 'due_at' => '2026-08-27',
        ]);
        $extensions = app(ThirdPartyEngagementCollaborationExtensionManager::class);

        try {
            $extensions->request($otherContact, $request, ['proposed_due_at' => '2026-09-03', 'reason' => 'Unauthorized contact.']);
            $this->fail('Only the exact recipient may request an extension.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $proposal = $extensions->request($contact, $request, ['proposed_due_at' => '2026-09-03', 'reason' => 'Additional preparation is required.']);
        Sanctum::actingAs($opener);
        $this->postJson("/api/third-party-collaboration-extensions/{$proposal->id}/decision", [
            'decision' => 'approved', 'summary' => 'Self approval.',
        ])->assertForbidden();
        Sanctum::actingAs($reviewer);
        $decision = $this->postJson("/api/third-party-collaboration-extensions/{$proposal->id}/decision", [
            'decision' => 'approved', 'summary' => 'The requested extension is approved.',
        ])->assertCreated()->assertJsonPath('data.decision', 'approved')->json('data');
        $this->assertSame('2026-08-27', $request->fresh()->due_at->toDateString());
        $this->assertSame('2026-09-03', $request->fresh()->effectiveDueContext()['due_at']);

        $laterProposal = $extensions->request($contact, $request, ['proposed_due_at' => '2026-09-10', 'reason' => 'A further extension is requested.']);
        $extensions->decide($reviewer, $laterProposal, ['decision' => 'rejected', 'summary' => 'No further extension is approved.']);
        $this->assertSame('2026-09-03', $request->fresh()->effectiveDueContext()['due_at']);
        $this->assertThrows(fn () => $proposal->update(['reason' => 'Rewritten']), \LogicException::class);
        $this->assertThrows(fn () => $proposal->decision->update(['summary' => 'Rewritten']), \LogicException::class);
        $proposal->refresh()->load('decision');

        $proposalPayload = $proposal->only(['third_party_engagement_collaboration_request_id', 'version', 'proposed_due_at', 'reason', 'recipient_vendor_user_id', 'recipient_snapshot', 'request_snapshot', 'current_due_context', 'requested_at']);
        $proposalPayload['proposed_due_at'] = $proposal->proposed_due_at->toDateString();
        $proposalPayload['requested_at'] = $proposal->requested_at->toIso8601String();
        $this->assertSame(hash('sha256', json_encode($proposalPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), $proposal->fingerprint);
        $decisionModel = $proposal->decision;
        $decisionPayload = $decisionModel->only(['third_party_collaboration_extension_id', 'decision', 'summary', 'decided_by', 'decider_snapshot', 'extension_snapshot']);
        $decisionPayload['decision'] = $decisionModel->decision->value;
        $decisionPayload['decided_at'] = $decisionModel->decided_at->toIso8601String();
        $this->assertSame(hash('sha256', json_encode($decisionPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), $decision['fingerprint']);

        $reminders = app(ThirdPartyEngagementCollaborationReminderManager::class);
        Carbon::setTestNow('2026-08-28 08:00:00');
        $this->assertSame(0, $reminders->reconcile(now()));
        Carbon::setTestNow('2026-08-31 08:00:00');
        $this->assertSame(1, $reminders->reconcile(now()));
        Carbon::setTestNow('2026-09-04 00:00:00');
        $this->assertSame(1, $reminders->reconcile(now()));
        $this->assertSame('2026-09-03', ThirdPartyEngagementCollaborationReminder::query()->latest('id')->firstOrFail()->effective_due_at->toDateString());
        Carbon::setTestNow('2026-09-08 00:00:00');
        $this->assertSame(1, app(ThirdPartyEngagementCollaborationEscalationManager::class)->reconcile(now()));
        $this->assertSame('2026-09-03', ThirdPartyEngagementCollaborationEscalation::query()->firstOrFail()->effective_due_at->toDateString());

        Sanctum::actingAs($reviewer);
        $this->getJson("/api/third-party-engagements/{$engagement->id}/collaboration-requests")
            ->assertOk()->assertJsonCount(2, 'data.0.extensions')->assertJsonPath('data.0.effective_due_at', '2026-09-03');
        Filament::setCurrentPanel(Filament::getPanel('vendor'));
        $this->actingAs($contact, 'vendor');
        Livewire::test(ViewCollaborationRequest::class, ['record' => $request->id])
            ->assertSee('Additional preparation is required.')
            ->assertSee('The requested extension is approved.')
            ->assertSee('No further extension is approved.');
        $overdueReminder = ThirdPartyEngagementCollaborationReminder::query()->where('type', 'overdue')->firstOrFail();
        $escalation = ThirdPartyEngagementCollaborationEscalation::query()->firstOrFail();
        DB::table('third_party_engagement_collaboration_reminders')->where('id', $overdueReminder->id)->update(['due_context_snapshot' => null]);
        DB::table('third_party_engagement_collaboration_escalations')->where('id', $escalation->id)->update(['due_context_snapshot' => null]);
        $migration = require database_path('migrations/2026_08_24_870000_create_third_party_collaboration_extensions.php');
        $migration->up();
        $this->assertSame($proposal->id, ThirdPartyEngagementCollaborationReminder::query()->findOrFail($overdueReminder->id)->due_context_snapshot['extension_id']);
        $this->assertSame($proposal->id, ThirdPartyEngagementCollaborationEscalation::query()->findOrFail($escalation->id)->due_context_snapshot['extension_id']);
        DB::table('third_party_engagement_collaboration_reminders')->where('id', $overdueReminder->id)->update(['due_context_fingerprint' => null]);
        $migration->up();
        $repairedReminder = ThirdPartyEngagementCollaborationReminder::query()->findOrFail($overdueReminder->id);
        $this->assertSame($proposal->decision->fingerprint, $repairedReminder->due_context_fingerprint);
        $this->assertSame($proposal->id, $repairedReminder->due_context_snapshot['extension_id']);
        DB::table('third_party_engagement_collaboration_reminders')->where('id', $overdueReminder->id)->update(['effective_due_at' => null]);
        $migration->up();
        $this->assertSame('2026-09-03', ThirdPartyEngagementCollaborationReminder::query()->findOrFail($overdueReminder->id)->effective_due_at->toDateString());
        Carbon::setTestNow();
    }

    public function test_extension_bounds_factories_and_retained_migration_are_exact(): void
    {
        Carbon::setTestNow('2026-08-24 10:00:00');
        $engagement = ThirdPartyEngagementMonitoringIndicator::factory()->create()->engagement;
        DB::table('third_party_engagements')->where('id', $engagement->id)->update(['term_end_at' => '2027-12-31']);
        $engagement->refresh();
        $opener = tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo('Manage Third Party Risk'));
        $reviewer = tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo('Manage Third Party Risk'));
        $contact = VendorUser::factory()->create(['vendor_id' => $engagement->vendor_id]);
        $request = app(ThirdPartyEngagementCollaborationManager::class)->open($opener, $engagement, [
            'category' => 'assurance', 'subject' => 'Bounded extension request', 'request_text' => 'Provide the requested response.',
            'recipient_vendor_user_id' => $contact->id, 'due_at' => '2026-09-01',
        ]);
        $manager = app(ThirdPartyEngagementCollaborationExtensionManager::class);
        for ($version = 1; $version <= 20; $version++) {
            $extension = $manager->request($contact, $request, ['proposed_due_at' => '2026-09-08', 'reason' => "Extension proposal {$version}."]);
            $manager->decide($reviewer, $extension, ['decision' => 'rejected', 'summary' => "Extension {$version} is rejected."]);
        }
        $this->assertDatabaseCount('third_party_collaboration_extensions', 20);
        try {
            $manager->request($contact, $request, ['proposed_due_at' => '2026-09-08', 'reason' => 'Overflow extension.']);
            $this->fail('The 21st extension proposal must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('request', $exception->errors());
        }

        $factoryExtension = ThirdPartyCollaborationExtension::factory()->create();
        $factoryDecision = ThirdPartyCollaborationExtensionDecision::factory()->create();
        $this->assertSame(64, strlen($factoryExtension->fingerprint));
        $this->assertSame(64, strlen($factoryDecision->fingerprint));
        $migration = require database_path('migrations/2026_08_24_870000_create_third_party_collaboration_extensions.php');
        $migration->down();
        $this->assertDatabaseHas('third_party_collaboration_extensions', ['id' => $factoryExtension->id]);
        $this->assertDatabaseHas('third_party_collaboration_extension_decisions', ['id' => $factoryDecision->id]);
        Carbon::setTestNow();
    }

    public function test_manager_reassigns_an_awaiting_request_without_rewriting_original_recipient_evidence(): void
    {
        Carbon::setTestNow('2026-08-24 10:00:00');
        $engagement = ThirdPartyEngagementMonitoringIndicator::factory()->create()->engagement;
        $manager = tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo(['Manage Third Party Risk', 'Read Vendors']));
        $reviewer = tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo(['Manage Third Party Risk', 'Read Vendors']));
        $original = VendorUser::factory()->create(['vendor_id' => $engagement->vendor_id]);
        $replacement = VendorUser::factory()->create(['vendor_id' => $engagement->vendor_id]);
        $foreign = VendorUser::factory()->create();
        $request = app(ThirdPartyEngagementCollaborationManager::class)->open($manager, $engagement, [
            'category' => 'assurance', 'subject' => 'Reassigned provider request', 'request_text' => 'Provide the current assurance response.',
            'recipient_vendor_user_id' => $original->id, 'due_at' => '2026-08-30',
        ]);
        $document = VendorDocument::factory()->create(['vendor_id' => $engagement->vendor_id, 'uploaded_by' => $original->id, 'document_type' => 'other',
            'name' => 'Reassignment evidence', 'file_path' => 'vendor-documents/reassignment.txt', 'file_name' => 'reassignment.txt', 'file_size' => 20,
            'mime_type' => 'text/plain', 'status' => 'pending']);
        Storage::disk('private')->put($document->file_path, 'reassignment evidence');
        $response = app(ThirdPartyEngagementCollaborationManager::class)->respond($original, $request, ['response_text' => 'Initial evidence.', 'vendor_document_ids' => [$document->id]]);
        app(ThirdPartyEngagementCollaborationManager::class)->decide($reviewer, $request, ['decision' => 'follow_up', 'summary' => 'A replacement contact must follow up.']);
        $evidence = $response->evidence->first();
        $reassignments = app(ThirdPartyEngagementCollaborationRecipientManager::class);
        $extensions = app(ThirdPartyEngagementCollaborationExtensionManager::class);
        $engagement->update(['term_end_at' => '2026-10-01']);
        $pendingExtension = $extensions->request($original, $request, ['proposed_due_at' => '2026-09-03', 'reason' => 'The original contact needs more time.']);
        try {
            $reassignments->reassign($manager, $request, ['recipient_vendor_user_id' => $replacement->id, 'reason' => 'Premature reassignment.']);
            $this->fail('A request with a pending due-date extension must not be reassigned.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('request', $exception->errors());
        }
        $extensions->decide($reviewer, $pendingExtension, ['decision' => 'rejected', 'summary' => 'The original deadline remains appropriate.']);

        Carbon::setTestNow('2026-08-27 08:00:00');
        $this->assertSame(1, app(ThirdPartyEngagementCollaborationReminderManager::class)->reconcile(now()));
        $priorReminder = ThirdPartyEngagementCollaborationReminder::query()->firstOrFail();
        $this->assertSame($original->id, $priorReminder->vendor_user_id);
        $this->assertTrue(DB::table('notifications')->where('id', $priorReminder->notification_id)->exists());
        $original->delete();
        try {
            $reassignments->reassign($manager, $request, ['recipient_vendor_user_id' => $foreign->id, 'reason' => 'Foreign recipient.']);
            $this->fail('A collaboration request cannot be reassigned across vendors.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        Sanctum::actingAs($manager);
        $reassignment = $this->postJson("/api/third-party-engagement-collaboration-requests/{$request->id}/reassign", [
            'recipient_vendor_user_id' => $replacement->id, 'reason' => 'The original contact is unavailable.',
        ])->assertCreated()->assertJsonPath('data.to_vendor_user_id', $replacement->id)->json('data');
        $this->assertSame($original->id, $request->fresh()->recipient_vendor_user_id);
        $this->assertSame($replacement->id, $request->fresh()->currentRecipientContext()['recipient_vendor_user_id']);
        $this->assertFalse(DB::table('notifications')->where('id', $priorReminder->notification_id)->exists());
        $this->assertDatabaseHas('third_party_engagement_collaboration_reminders', ['id' => $priorReminder->id]);
        $this->actingAs($original, 'vendor')->get(route('vendor.third-party-collaboration-evidence.download', $evidence))->assertForbidden();
        $this->actingAs($replacement, 'vendor')->get(route('vendor.third-party-collaboration-evidence.download', $evidence))->assertOk()->assertStreamedContent('reassignment evidence');
        Carbon::setTestNow('2026-08-31 00:00:01');
        $this->assertSame(1, app(ThirdPartyEngagementCollaborationReminderManager::class)->reconcile(now()));
        $this->assertSame($replacement->id, ThirdPartyEngagementCollaborationReminder::query()->latest('id')->firstOrFail()->vendor_user_id);
        $this->actingAs($manager, 'web');
        Livewire::test(EngagementsRelationManager::class, ['ownerRecord' => $engagement->vendor, 'pageClass' => ViewThirdPartyRisk::class])
            ->assertTableActionVisible('reassign_collaboration', $engagement);
        $rendered = view('filament.third-party-engagement', ['engagement' => $engagement->fresh()->load(['collaborationRequests.reassignments.actor'])])->render();
        $this->assertStringContainsString('The original contact is unavailable.', $rendered);

        try {
            app(ThirdPartyEngagementCollaborationManager::class)->respond($original, $request, ['response_text' => 'Stale recipient response.']);
            $this->fail('The former recipient must lose response authority immediately.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $event = app(ThirdPartyEngagementCollaborationManager::class)->respond($replacement, $request, ['response_text' => 'Replacement recipient response.']);
        $this->assertSame($replacement->id, $event->actor_id);
        $this->assertSame($reassignment['fingerprint'], $event->request_snapshot['current_recipient_context']['fingerprint']);
        $lateRecipient = VendorUser::factory()->create(['vendor_id' => $engagement->vendor_id]);
        try {
            $reassignments->reassign($manager, $request, ['recipient_vendor_user_id' => $lateRecipient->id, 'reason' => 'Late reassignment.']);
            $this->fail('A responded request must not be reassigned.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('request', $exception->errors());
        }

        Filament::setCurrentPanel(Filament::getPanel('vendor'));
        $this->actingAs($original, 'vendor');
        Livewire::test(ListCollaborationRequests::class)->assertCanNotSeeTableRecords([$request]);
        $this->actingAs($replacement, 'vendor');
        Livewire::test(ViewCollaborationRequest::class, ['record' => $request->id])->assertSee('The original contact is unavailable.');

        $record = ThirdPartyCollaborationRecipientReassignment::query()->firstOrFail();
        $payload = $record->only(['third_party_engagement_collaboration_request_id', 'version', 'from_vendor_user_id', 'to_vendor_user_id', 'from_recipient_snapshot', 'to_recipient_snapshot', 'prior_recipient_context', 'request_snapshot', 'reason', 'reassigned_by', 'actor_snapshot']);
        $payload['reassigned_at'] = $record->reassigned_at->toIso8601String();
        $this->assertSame(hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), $record->fingerprint);
        $this->assertThrows(fn () => $record->update(['reason' => 'Rewritten']), \LogicException::class);

        Sanctum::actingAs($manager);
        $this->getJson("/api/third-party-engagements/{$engagement->id}/collaboration-requests")
            ->assertOk()->assertJsonPath('data.0.current_recipient_vendor_user_id', $replacement->id)
            ->assertJsonPath('data.0.reassignments.0.reason', 'The original contact is unavailable.');
        $factory = ThirdPartyCollaborationRecipientReassignment::factory()->create();
        $factoryPayload = $factory->only(['third_party_engagement_collaboration_request_id', 'version', 'from_vendor_user_id', 'to_vendor_user_id', 'from_recipient_snapshot', 'to_recipient_snapshot', 'prior_recipient_context', 'request_snapshot', 'reason', 'reassigned_by', 'actor_snapshot']);
        $factoryPayload['reassigned_at'] = $factory->reassigned_at->toIso8601String();
        $this->assertSame(hash('sha256', json_encode($factoryPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), $factory->fingerprint);
        $migration = require database_path('migrations/2026_08_24_880000_create_third_party_collaboration_recipient_reassignments.php');
        $migration->down();
        $this->assertDatabaseHas('third_party_collaboration_recipient_reassignments', ['id' => $record->id]);
        $this->assertDatabaseHas('third_party_collaboration_recipient_reassignments', ['id' => $factory->id]);
        Carbon::setTestNow();
    }

    public function test_recipient_reassignment_history_bound_is_exact(): void
    {
        $engagement = ThirdPartyEngagementMonitoringIndicator::factory()->create()->engagement;
        $manager = tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo('Manage Third Party Risk'));
        $first = VendorUser::factory()->create(['vendor_id' => $engagement->vendor_id]);
        $second = VendorUser::factory()->create(['vendor_id' => $engagement->vendor_id]);
        $request = app(ThirdPartyEngagementCollaborationManager::class)->open($manager, $engagement, [
            'category' => 'assurance', 'subject' => 'Bounded recipient history', 'request_text' => 'Provide the response.',
            'recipient_vendor_user_id' => $first->id, 'due_at' => today()->addDays(14)->toDateString(),
        ]);
        $recipientManager = app(ThirdPartyEngagementCollaborationRecipientManager::class);
        for ($version = 1; $version <= 20; $version++) {
            $recipientManager->reassign($manager, $request, [
                'recipient_vendor_user_id' => $version % 2 === 1 ? $second->id : $first->id,
                'reason' => "Recipient reassignment {$version}.",
            ]);
        }
        $this->assertDatabaseCount('third_party_collaboration_recipient_reassignments', 20);
        try {
            $recipientManager->reassign($manager, $request, ['recipient_vendor_user_id' => $second->id, 'reason' => 'Overflow reassignment.']);
            $this->fail('The 21st recipient reassignment must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('request', $exception->errors());
        }
    }

    public function test_manager_cancels_an_awaiting_request_with_retained_terminal_evidence(): void
    {
        Carbon::setTestNow('2026-08-25 09:00:00');
        $engagement = ThirdPartyEngagementMonitoringIndicator::factory()->create()->engagement;
        $manager = tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo(['Manage Third Party Risk', 'Read Vendors']));
        $reviewer = tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo(['Manage Third Party Risk', 'Read Vendors']));
        $recipient = VendorUser::factory()->create(['vendor_id' => $engagement->vendor_id]);
        $request = app(ThirdPartyEngagementCollaborationManager::class)->open($manager, $engagement, [
            'category' => 'assurance', 'subject' => 'Obsolete evidence request', 'request_text' => 'Provide evidence that is no longer required.',
            'recipient_vendor_user_id' => $recipient->id, 'due_at' => '2026-08-27',
        ]);
        try {
            app(ThirdPartyEngagementCollaborationCancellationManager::class)->cancel(User::factory()->create(), $request, ['reason' => []]);
            $this->fail('Direct cancellation must authorize before disclosing validation state.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $engagement->update(['term_end_at' => '2026-10-01']);
        $extensionManager = app(ThirdPartyEngagementCollaborationExtensionManager::class);
        $pendingExtension = $extensionManager->request($recipient, $request, ['proposed_due_at' => '2026-09-03', 'reason' => 'More time is requested.']);
        try {
            app(ThirdPartyEngagementCollaborationCancellationManager::class)->cancel($manager, $request, ['reason' => 'Premature cancellation.']);
            $this->fail('A pending due-date decision must be terminal before cancellation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('request', $exception->errors());
        }
        $extensionManager->decide($reviewer, $pendingExtension, ['decision' => 'rejected', 'summary' => 'The current deadline remains.']);
        Carbon::setTestNow('2026-08-25 10:00:00');
        $this->assertSame(1, app(ThirdPartyEngagementCollaborationReminderManager::class)->reconcile(now()));
        $reminder = ThirdPartyEngagementCollaborationReminder::query()->firstOrFail();
        $this->assertTrue(DB::table('notifications')->where('id', $reminder->notification_id)->exists());

        $this->actingAs($manager, 'web');
        Livewire::test(EngagementsRelationManager::class, ['ownerRecord' => $engagement->vendor, 'pageClass' => ViewThirdPartyRisk::class])
            ->assertTableActionVisible('cancel_collaboration', $engagement);

        Sanctum::actingAs($manager);
        $cancellation = $this->postJson("/api/third-party-engagement-collaboration-requests/{$request->id}/cancel", [
            'reason' => 'The assurance scope changed and this request is no longer required.',
        ])->assertCreated()->assertJsonPath('data.third_party_engagement_collaboration_request_id', $request->id)->json('data');
        $this->assertFalse(DB::table('notifications')->where('id', $reminder->notification_id)->exists());
        $this->assertDatabaseHas('third_party_engagement_collaboration_reminders', ['id' => $reminder->id]);
        $this->assertSame(0, app(ThirdPartyEngagementCollaborationReminderManager::class)->reconcile(now()->addDays(10)));

        try {
            app(ThirdPartyEngagementCollaborationManager::class)->respond($recipient, $request, ['response_text' => 'Late response.']);
            $this->fail('A cancelled request must be terminal.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('request', $exception->errors());
        }
        try {
            app(ThirdPartyEngagementCollaborationAcknowledgementManager::class)->acknowledge($recipient, $request);
            $this->fail('A cancelled request must not accept a later receipt acknowledgement.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('request', $exception->errors());
        }
        try {
            app(ThirdPartyEngagementCollaborationCancellationManager::class)->cancel($manager, $request, ['reason' => 'Duplicate cancellation.']);
            $this->fail('A collaboration request can be cancelled only once.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('request', $exception->errors());
        }

        Filament::setCurrentPanel(Filament::getPanel('vendor'));
        $this->actingAs($recipient, 'vendor');
        Livewire::test(ViewCollaborationRequest::class, ['record' => $request->id])
            ->assertSee('Cancelled')
            ->assertSee('The assurance scope changed and this request is no longer required.');
        $record = ThirdPartyCollaborationRequestCancellation::query()->firstOrFail();
        $payload = $record->only(['third_party_engagement_collaboration_request_id', 'latest_event_id', 'request_snapshot', 'latest_event_snapshot', 'recipient_context', 'due_context', 'reason', 'cancelled_by', 'actor_snapshot']);
        $payload['cancelled_at'] = $record->cancelled_at->toIso8601String();
        $this->assertSame(hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), $record->fingerprint);
        $this->assertThrows(fn () => $record->update(['reason' => 'Rewritten']), \LogicException::class);
        $this->assertSame($cancellation['fingerprint'], $record->fingerprint);
        $this->getJson("/api/third-party-engagements/{$engagement->id}/collaboration-requests")
            ->assertOk()->assertJsonPath('data.0.cancellation.reason', 'The assurance scope changed and this request is no longer required.')
            ->assertJsonPath('data.0.cancellation.latest_event_snapshot.evidence_manifest', []);
        $rendered = view('filament.third-party-engagement', ['engagement' => $engagement->fresh()->load(['collaborationRequests.cancellation.actor', 'collaborationRequests.recipient', 'collaborationRequests.opener', 'collaborationRequests.reassignments', 'collaborationRequests.extensions.decision', 'collaborationRequests.events.evidence', 'collaborationRequests.reminders', 'collaborationRequests.escalation'])])->render();
        $this->assertStringContainsString('Retained cancellation evidence', $rendered);
        $this->assertStringContainsString('evidence_manifest', $rendered);
        $factory = ThirdPartyCollaborationRequestCancellation::factory()->create();
        $factoryPayload = $factory->only(['third_party_engagement_collaboration_request_id', 'latest_event_id', 'request_snapshot', 'latest_event_snapshot', 'recipient_context', 'due_context', 'reason', 'cancelled_by', 'actor_snapshot']);
        $factoryPayload['cancelled_at'] = $factory->cancelled_at->toIso8601String();
        $this->assertSame(hash('sha256', json_encode($factoryPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), $factory->fingerprint);
        $migration = require database_path('migrations/2026_08_24_890000_create_third_party_collaboration_request_cancellations.php');
        $migration->down();
        $this->assertDatabaseHas('third_party_collaboration_request_cancellations', ['id' => $record->id]);
        $this->assertDatabaseHas('third_party_collaboration_request_cancellations', ['id' => $factory->id]);
        Carbon::setTestNow();
    }

    public function test_exact_current_recipient_acknowledges_each_assignment_context_once(): void
    {
        Carbon::setTestNow('2026-08-25 11:00:00');
        $engagement = ThirdPartyEngagementMonitoringIndicator::factory()->create()->engagement;
        $manager = tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo(['Manage Third Party Risk', 'Read Vendors']));
        $original = VendorUser::factory()->create(['vendor_id' => $engagement->vendor_id]);
        $replacement = VendorUser::factory()->create(['vendor_id' => $engagement->vendor_id]);
        $request = app(ThirdPartyEngagementCollaborationManager::class)->open($manager, $engagement, [
            'category' => 'assurance', 'subject' => 'Acknowledge current request', 'request_text' => 'Confirm that this request reached the assigned portal account.',
            'recipient_vendor_user_id' => $original->id, 'due_at' => '2026-09-05',
        ]);
        $acknowledgements = app(ThirdPartyEngagementCollaborationAcknowledgementManager::class);
        try {
            $acknowledgements->acknowledge(VendorUser::factory()->create(['vendor_id' => $engagement->vendor_id]), $request);
            $this->fail('Only the exact current recipient may acknowledge the request.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $first = $acknowledgements->acknowledge($original, $request);
        $this->assertSame($request->fingerprint, $first->recipient_context['fingerprint']);
        try {
            $acknowledgements->acknowledge($original, $request);
            $this->fail('A recipient context can be acknowledged only once.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('request', $exception->errors());
        }

        $reassignment = app(ThirdPartyEngagementCollaborationRecipientManager::class)->reassign($manager, $request, [
            'recipient_vendor_user_id' => $replacement->id, 'reason' => 'The replacement now owns the response.',
        ]);
        try {
            $acknowledgements->acknowledge($original, $request);
            $this->fail('The former recipient must lose acknowledgement authority.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        Carbon::setTestNow('2026-08-25 12:00:00');
        $second = $acknowledgements->acknowledge($replacement, $request);
        $this->assertSame($reassignment->fingerprint, $second->recipient_context['fingerprint']);
        $this->assertDatabaseCount('third_party_collaboration_request_acknowledgements', 2);

        Filament::setCurrentPanel(Filament::getPanel('vendor'));
        $this->actingAs($replacement, 'vendor');
        Livewire::test(ViewCollaborationRequest::class, ['record' => $request->id])
            ->assertSee('Receipt acknowledgement history')
            ->assertSee($first->fingerprint)
            ->assertSee($second->fingerprint);
        app(ThirdPartyEngagementCollaborationManager::class)->respond($replacement, $request, ['response_text' => 'The request is now answered.']);
        try {
            $acknowledgements->acknowledge($replacement, $request);
            $this->fail('A responded request must not accept a later receipt acknowledgement.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('request', $exception->errors());
        }
        $payload = $second->only(['third_party_engagement_collaboration_request_id', 'latest_event_id', 'recipient_context_fingerprint', 'request_snapshot', 'latest_event_snapshot', 'recipient_context', 'due_context', 'vendor_user_id', 'recipient_snapshot']);
        $payload['acknowledged_at'] = $second->acknowledged_at->toIso8601String();
        $this->assertSame(hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), $second->fingerprint);
        $this->assertThrows(fn () => $second->update(['recipient_snapshot' => []]), \LogicException::class);
        Sanctum::actingAs($manager);
        $this->getJson("/api/third-party-engagements/{$engagement->id}/collaboration-requests")
            ->assertOk()->assertJsonCount(2, 'data.0.acknowledgements')
            ->assertJsonPath('data.0.acknowledgements.1.recipient_context_fingerprint', $reassignment->fingerprint);
        $factory = ThirdPartyCollaborationRequestAcknowledgement::factory()->create();
        $factoryPayload = $factory->only(['third_party_engagement_collaboration_request_id', 'latest_event_id', 'recipient_context_fingerprint', 'request_snapshot', 'latest_event_snapshot', 'recipient_context', 'due_context', 'vendor_user_id', 'recipient_snapshot']);
        $factoryPayload['acknowledged_at'] = $factory->acknowledged_at->toIso8601String();
        $this->assertSame(hash('sha256', json_encode($factoryPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), $factory->fingerprint);
        $migration = require database_path('migrations/2026_08_24_900000_create_third_party_collaboration_request_acknowledgements.php');
        $migration->down();
        $this->assertDatabaseHas('third_party_collaboration_request_acknowledgements', ['id' => $first->id]);
        $this->assertDatabaseHas('third_party_collaboration_request_acknowledgements', ['id' => $factory->id]);
        Carbon::setTestNow();
    }

    public function test_recipient_acknowledgement_context_bound_is_exact(): void
    {
        $engagement = ThirdPartyEngagementMonitoringIndicator::factory()->create()->engagement;
        $manager = tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo('Manage Third Party Risk'));
        $first = VendorUser::factory()->create(['vendor_id' => $engagement->vendor_id]);
        $second = VendorUser::factory()->create(['vendor_id' => $engagement->vendor_id]);
        $request = app(ThirdPartyEngagementCollaborationManager::class)->open($manager, $engagement, [
            'category' => 'assurance', 'subject' => 'Bounded receipt contexts', 'request_text' => 'Acknowledge every governed recipient context.',
            'recipient_vendor_user_id' => $first->id, 'due_at' => today()->addDays(14)->toDateString(),
        ]);
        $acknowledgements = app(ThirdPartyEngagementCollaborationAcknowledgementManager::class);
        $recipients = app(ThirdPartyEngagementCollaborationRecipientManager::class);
        $acknowledgements->acknowledge($first, $request);
        for ($version = 1; $version <= 20; $version++) {
            $recipient = $version % 2 === 1 ? $second : $first;
            $recipients->reassign($manager, $request, ['recipient_vendor_user_id' => $recipient->id, 'reason' => "Governed assignment {$version}."]);
            $acknowledgements->acknowledge($recipient, $request);
        }
        $this->assertDatabaseCount('third_party_collaboration_recipient_reassignments', 20);
        $this->assertDatabaseCount('third_party_collaboration_request_acknowledgements', 21);
        try {
            $recipients->reassign($manager, $request, ['recipient_vendor_user_id' => $second->id, 'reason' => 'A 22nd acknowledgement context.']);
            $this->fail('The 22nd recipient acknowledgement context must be unreachable.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('request', $exception->errors());
        }
        $this->assertDatabaseCount('third_party_collaboration_request_acknowledgements', 21);
    }

    public function test_accepted_request_requires_separated_governed_closure_and_exposes_scoped_evidence(): void
    {
        Storage::fake('private');
        $engagement = ThirdPartyEngagementMonitoringIndicator::factory()->create()->engagement;
        $opener = tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo(['Manage Third Party Risk', 'Read Vendors']));
        $acceptor = tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo(['Manage Third Party Risk', 'Read Vendors']));
        $closer = tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo(['Manage Third Party Risk', 'Read Vendors']));
        $contact = VendorUser::factory()->create(['vendor_id' => $engagement->vendor_id]);
        $replacement = VendorUser::factory()->create(['vendor_id' => $engagement->vendor_id]);
        $document = VendorDocument::factory()->create([
            'vendor_id' => $engagement->vendor_id, 'uploaded_by' => $replacement->id, 'document_type' => 'other',
            'name' => 'Closure evidence', 'file_path' => 'vendor-documents/closure.txt', 'file_name' => 'closure.txt',
            'file_size' => 23, 'mime_type' => 'text/plain', 'status' => 'pending',
        ]);
        Storage::disk('private')->put($document->file_path, 'closure evidence bytes');
        $collaboration = app(ThirdPartyEngagementCollaborationManager::class);
        $closures = app(ThirdPartyEngagementCollaborationClosureManager::class);
        $request = $collaboration->open($opener, $engagement, [
            'category' => 'assurance', 'subject' => 'Close accepted assurance request',
            'request_text' => 'Provide and complete the governed assurance response.',
            'recipient_vendor_user_id' => $contact->id, 'due_at' => today()->addWeek()->toDateString(),
        ]);
        try {
            $closures->close($closer, $request, ['summary' => 'Premature closure.']);
            $this->fail('An awaiting request must not close.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('request', $exception->errors());
        }
        app(ThirdPartyEngagementCollaborationRecipientManager::class)->reassign($opener, $request, [
            'recipient_vendor_user_id' => $replacement->id, 'reason' => 'The replacement owns the final response.',
        ]);
        $collaboration->respond($replacement, $request, ['response_text' => 'The requested assurance response is supplied.', 'vendor_document_ids' => [$document->id]]);
        $accepted = $collaboration->decide($acceptor, $request, ['decision' => 'accepted', 'summary' => 'The response is accepted.']);
        foreach ([$opener, $acceptor] as $conflictedActor) {
            try {
                $closures->close($conflictedActor, $request, ['summary' => 'Conflicted closure.']);
                $this->fail('Opening and acceptance actors must not close the request.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
        Event::listen(NotificationSending::class, fn (): bool => false);
        try {
            $closures->close($closer, $request, ['summary' => 'A cancelled delivery must roll back closure.']);
            $this->fail('A cancelled in-app delivery must roll back closure evidence.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('not accepted', $exception->getMessage());
        }
        Event::forget(NotificationSending::class);
        $this->assertDatabaseMissing('third_party_collaboration_request_closures', ['third_party_engagement_collaboration_request_id' => $request->id]);
        $this->assertDatabaseCount('third_party_collaboration_closure_deliveries', 0);
        Sanctum::actingAs($closer);
        $closureId = $this->postJson("/api/third-party-engagement-collaboration-requests/{$request->id}/close", [
            'summary' => 'A separate manager administratively closes the accepted in-product request.',
            'actor_snapshot' => ['id' => 999],
        ])->assertUnprocessable()->json('data.id');
        $this->assertNull($closureId);
        $closureId = $this->postJson("/api/third-party-engagement-collaboration-requests/{$request->id}/close", [
            'summary' => 'A separate manager administratively closes the accepted in-product request.',
        ])->assertCreated()->assertJsonPath('data.accepted_event_id', $accepted->id)->json('data.id');
        $closure = ThirdPartyCollaborationRequestClosure::query()->findOrFail($closureId);
        $delivery = $closure->delivery()->firstOrFail();
        $deliveryPayload = $delivery->only([
            'third_party_collaboration_request_closure_id', 'third_party_engagement_collaboration_request_id',
            'vendor_user_id', 'channel', 'notification_id', 'recipient_snapshot', 'closure_snapshot',
        ]);
        $deliveryPayload['attempted_at'] = $delivery->attempted_at->toIso8601String();
        $deliveryPayload['delivered_at'] = $delivery->delivered_at->toIso8601String();
        $this->assertSame(hash('sha256', CanonicalJson::encode($deliveryPayload)), $delivery->fingerprint);
        $databaseOrderedPayload = json_decode(json_encode($deliveryPayload, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $databaseOrderedPayload['closure_snapshot'] = array_reverse($databaseOrderedPayload['closure_snapshot'], true);
        $databaseOrderedPayload['recipient_snapshot'] = array_reverse($databaseOrderedPayload['recipient_snapshot'], true);
        $this->assertSame($delivery->fingerprint, hash('sha256', CanonicalJson::encode($databaseOrderedPayload)));
        $this->assertSame($replacement->id, $delivery->vendor_user_id);
        $this->assertTrue($delivery->recipient_snapshot['activated']);
        $this->assertNull($delivery->recipient_snapshot['deleted_at']);
        $this->assertSame($closure->fingerprint, $delivery->closure_snapshot['fingerprint']);
        $this->assertDatabaseHas('notifications', ['id' => $delivery->notification_id, 'notifiable_id' => $replacement->id]);
        $payload = $closure->only(['third_party_engagement_collaboration_request_id', 'accepted_event_id', 'request_snapshot', 'accepted_event_snapshot', 'recipient_context', 'due_context', 'escalation_snapshot']);
        $payload['response_recorded_at'] = $closure->response_recorded_at->toIso8601String();
        $payload['timeliness_status'] = $closure->timeliness_status->value;
        $payload['days_late'] = $closure->days_late;
        $payload['calendar_timezone'] = $closure->calendar_timezone;
        $payload['timeliness_fingerprint'] = $closure->timeliness_fingerprint;
        $payload['fingerprint_version'] = $closure->fingerprint_version;
        $payload += $closure->only(['summary', 'closed_by', 'actor_snapshot']);
        $payload['closed_at'] = $closure->closed_at->toIso8601String();
        $this->assertSame(hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), $closure->fingerprint);
        $this->assertSame('on_time', $closure->timeliness_status->value);
        $this->assertSame(0, $closure->days_late);
        $this->assertCount(1, $closure->accepted_event_snapshot['response']['evidence_manifest']);
        $this->assertSame('closure.txt', $closure->accepted_event_snapshot['response']['evidence_manifest'][0]['file_name_snapshot']);
        $this->assertSame($accepted->fingerprint, $closure->accepted_event_snapshot['acceptance']['fingerprint']);
        $this->postJson("/api/third-party-engagement-collaboration-requests/{$request->id}/close", ['summary' => 'Duplicate.'])->assertUnprocessable();
        $this->getJson("/api/third-party-engagements/{$engagement->id}/collaboration-requests")
            ->assertOk()->assertJsonPath('data.0.closure.id', $closure->id)
            ->assertJsonPath('data.0.closure.accepted_event_snapshot.acceptance.fingerprint', $accepted->fingerprint)
            ->assertJsonPath('data.0.closure.accepted_event_snapshot.response.evidence_manifest.0.file_name_snapshot', 'closure.txt')
            ->assertJsonPath('data.0.closure.timeliness_status', 'on_time')
            ->assertJsonPath('data.0.closure.days_late', 0)
            ->assertJsonPath('data.0.closure.calendar_timezone', 'UTC')
            ->assertJsonPath('data.0.closure.timeliness_fingerprint', $closure->timeliness_fingerprint)
            ->assertJsonPath('data.0.closure.fingerprint_version', 'closure/v2')
            ->assertJsonPath('data.0.closure.delivery.notification_id', $delivery->notification_id)
            ->assertJsonPath('data.0.closure.delivery.recipient.email', $replacement->email)
            ->assertJsonPath('data.0.closure.delivery.fingerprint', $delivery->fingerprint);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        $this->actingAs($closer, 'web');
        $operatorEvidence = view('filament.third-party-engagement', [
            'engagement' => $engagement->fresh()->load([
                'collaborationRequests.closure.actor', 'collaborationRequests.closure.delivery.recipient', 'collaborationRequests.recipient', 'collaborationRequests.opener',
                'collaborationRequests.reassignments', 'collaborationRequests.extensions.decision',
                'collaborationRequests.events.evidence', 'collaborationRequests.reminders', 'collaborationRequests.escalation',
            ]),
        ])->render();
        $this->assertStringContainsString('Response timeliness: On time', $operatorEvidence);
        $this->assertStringContainsString('UTC calendar', $operatorEvidence);
        $this->assertStringContainsString($closure->timeliness_fingerprint, $operatorEvidence);
        $this->assertStringContainsString('closure/v2', $operatorEvidence);
        $this->assertStringContainsString($delivery->notification_id, $operatorEvidence);
        $this->assertStringContainsString($delivery->fingerprint, $operatorEvidence);
        $document->delete();
        $this->getJson("/api/third-party-engagements/{$engagement->id}/collaboration-requests")
            ->assertOk()
            ->assertJsonCount(0, 'data.0.closure.accepted_event_snapshot.response.evidence_manifest')
            ->assertJsonCount(0, 'data.0.closure.delivery.closure_snapshot.accepted_event_snapshot.response.evidence_manifest');
        $persistedClosure = ThirdPartyCollaborationRequestClosure::query()->findOrFail($closure->id);
        $this->assertSame('closure.txt', $persistedClosure->accepted_event_snapshot['response']['evidence_manifest'][0]['file_name_snapshot']);
        $this->assertSame('closure.txt', $delivery->fresh()->closure_snapshot['accepted_event_snapshot']['response']['evidence_manifest'][0]['file_name_snapshot']);
        Filament::setCurrentPanel(Filament::getPanel('vendor'));
        $this->actingAs($contact, 'vendor');
        Livewire::test(ListCollaborationRequests::class)->assertCanNotSeeTableRecords([$request]);
        $this->actingAs($replacement, 'vendor');
        Livewire::test(ViewCollaborationRequest::class, ['record' => $request->id])
            ->assertSee('Staff closure')->assertSee($closure->fingerprint)
            ->assertSee('On time')->assertSee('UTC')->assertSee($closure->timeliness_fingerprint)
            ->assertSee('closure/v2')->assertSee($delivery->notification_id)->assertSee($delivery->fingerprint)
            ->assertDontSee($replacement->email)->assertDontSee($closer->email);
        $portalRequest = CollaborationRequestResource::getEloquentQuery()->findOrFail($request->id);
        $this->assertEqualsCanonicalizing(
            ['id', 'third_party_engagement_collaboration_request_id', 'response_recorded_at', 'timeliness_status', 'days_late', 'calendar_timezone', 'timeliness_fingerprint', 'fingerprint_version', 'summary', 'closed_at', 'fingerprint'],
            array_keys($portalRequest->closure->getAttributes()),
        );
        $this->assertEqualsCanonicalizing(
            ['channel', 'notification_id', 'attempted_at', 'delivered_at', 'fingerprint'],
            array_keys($portalRequest->closure->delivery->toArray()),
        );
        DB::table('notifications')->where('id', $delivery->notification_id)->delete();
        $this->assertDatabaseHas('third_party_collaboration_closure_deliveries', ['id' => $delivery->id]);
        $this->assertThrows(fn () => $closure->update(['summary' => 'Rewritten.']), \LogicException::class);
        $factory = ThirdPartyCollaborationRequestClosure::factory()->create();
        $factoryPayload = $factory->only(['third_party_engagement_collaboration_request_id', 'accepted_event_id', 'request_snapshot', 'accepted_event_snapshot', 'recipient_context', 'due_context', 'escalation_snapshot']);
        $factoryPayload['response_recorded_at'] = $factory->response_recorded_at->toIso8601String();
        $factoryPayload['timeliness_status'] = $factory->timeliness_status->value;
        $factoryPayload['days_late'] = $factory->days_late;
        $factoryPayload['calendar_timezone'] = $factory->calendar_timezone;
        $factoryPayload['timeliness_fingerprint'] = $factory->timeliness_fingerprint;
        $factoryPayload['fingerprint_version'] = $factory->fingerprint_version;
        $factoryPayload += $factory->only(['summary', 'closed_by', 'actor_snapshot']);
        $factoryPayload['closed_at'] = $factory->closed_at->toIso8601String();
        $this->assertSame(hash('sha256', json_encode($factoryPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), $factory->fingerprint);
        $this->assertSame('responded', data_get($factory->accepted_event_snapshot, 'response.status'));
        $factoryDelivery = ThirdPartyCollaborationClosureDelivery::factory()->create();
        $factoryDeliveryPayload = $factoryDelivery->only([
            'third_party_collaboration_request_closure_id', 'third_party_engagement_collaboration_request_id',
            'vendor_user_id', 'channel', 'notification_id', 'recipient_snapshot', 'closure_snapshot',
        ]);
        $factoryDeliveryPayload['attempted_at'] = $factoryDelivery->attempted_at->toIso8601String();
        $factoryDeliveryPayload['delivered_at'] = $factoryDelivery->delivered_at->toIso8601String();
        $this->assertSame(hash('sha256', CanonicalJson::encode($factoryDeliveryPayload)), $factoryDelivery->fingerprint);
        $this->assertThrows(fn () => $delivery->update(['channel' => 'email']), \LogicException::class);
        $migration = require database_path('migrations/2026_08_25_030000_create_third_party_collaboration_closure_deliveries.php');
        $migration->down();
        $this->assertDatabaseHas('third_party_collaboration_closure_deliveries', ['id' => $delivery->id]);
        $closureMigration = require database_path('migrations/2026_08_25_010000_create_third_party_collaboration_request_closures.php');
        $closureMigration->down();
        $this->assertDatabaseHas('third_party_collaboration_request_closures', ['id' => $closure->id]);
    }

    public function test_closure_timeliness_uses_exact_effective_due_date_and_retained_backfill(): void
    {
        Carbon::setTestNow('2026-08-24 08:00:00');
        $engagement = ThirdPartyEngagementMonitoringIndicator::factory()->create()->engagement;
        $engagement->update(['term_end_at' => '2026-12-31']);
        $opener = tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo('Manage Third Party Risk'));
        $acceptor = tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo('Manage Third Party Risk'));
        $closer = tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo('Manage Third Party Risk'));
        $contact = VendorUser::factory()->create(['vendor_id' => $engagement->vendor_id]);
        $collaboration = app(ThirdPartyEngagementCollaborationManager::class);
        $closures = app(ThirdPartyEngagementCollaborationClosureManager::class);

        $onTimeRequest = $collaboration->open($opener, $engagement, [
            'category' => 'assurance', 'subject' => 'Exact due-day response', 'request_text' => 'Respond on the due date.',
            'recipient_vendor_user_id' => $contact->id, 'due_at' => '2026-08-24',
        ]);
        Carbon::setTestNow('2026-08-24 23:59:59');
        $collaboration->respond($contact, $onTimeRequest, ['response_text' => 'Recorded at the end of the due date.']);
        $collaboration->decide($acceptor, $onTimeRequest, ['decision' => 'accepted', 'summary' => 'Accepted.']);
        $onTime = $closures->close($closer, $onTimeRequest, ['summary' => 'Closed with an on-time response.']);
        $this->assertSame('on_time', $onTime->timeliness_status->value);
        $this->assertSame(0, $onTime->days_late);
        $this->assertSame('2026-08-24 23:59:59', $onTime->response_recorded_at->format('Y-m-d H:i:s'));

        Carbon::setTestNow('2026-08-24 10:00:00+00:00');
        $offsetRequest = $collaboration->open($opener, $engagement, [
            'category' => 'evidence', 'subject' => 'UTC calendar response', 'request_text' => 'Use the retained UTC calendar boundary.',
            'recipient_vendor_user_id' => $contact->id, 'due_at' => '2026-08-24',
        ]);
        Carbon::setTestNow('2026-08-25 00:30:00+01:00');
        $collaboration->respond($contact, $offsetRequest, ['response_text' => 'Local next day but UTC due date.']);
        $collaboration->decide($acceptor, $offsetRequest, ['decision' => 'accepted', 'summary' => 'Accepted in UTC.']);
        $offsetClosure = $closures->close($closer, $offsetRequest, ['summary' => 'Closed against UTC.']);
        $this->assertSame('on_time', $offsetClosure->timeliness_status->value);
        $this->assertSame('UTC', $offsetClosure->calendar_timezone);
        $this->assertSame('2026-08-24T23:30:00+00:00', $offsetClosure->response_recorded_at->toIso8601String());

        Carbon::setTestNow('2026-08-24 09:00:00');
        $lateRequest = $collaboration->open($opener, $engagement, [
            'category' => 'resilience', 'subject' => 'Extension-aware late response', 'request_text' => 'Respond against the approved extension.',
            'recipient_vendor_user_id' => $contact->id, 'due_at' => '2026-08-24',
        ]);
        $extension = app(ThirdPartyEngagementCollaborationExtensionManager::class)->request($contact, $lateRequest, [
            'proposed_due_at' => '2026-08-26', 'reason' => 'A governed extension is needed.',
        ]);
        $decision = app(ThirdPartyEngagementCollaborationExtensionManager::class)->decide($acceptor, $extension, [
            'decision' => 'approved', 'summary' => 'Extension approved.',
        ]);
        Carbon::setTestNow('2026-08-27 00:00:01');
        $collaboration->respond($contact, $lateRequest, ['response_text' => 'Recorded on the next calendar day.']);
        $collaboration->decide($acceptor, $lateRequest, ['decision' => 'accepted', 'summary' => 'Accepted after the extension date.']);
        $late = $closures->close($closer, $lateRequest, ['summary' => 'Closed with a one-day-late response.']);
        $this->assertSame('late', $late->timeliness_status->value);
        $this->assertSame(1, $late->days_late);
        $this->assertSame('2026-08-26', $late->due_context['due_at']);
        $this->assertSame($decision->fingerprint, $late->due_context['fingerprint']);
        $timelinessPayload = [
            'accepted_event_id' => $late->accepted_event_id,
            'response_recorded_at' => $late->response_recorded_at->toIso8601String(),
            'due_context' => [
                'due_at' => $late->due_context['due_at'], 'fingerprint' => $late->due_context['fingerprint'],
                'extension_id' => $late->due_context['extension_id'], 'decision_id' => $late->due_context['decision_id'],
            ],
            'calendar_timezone' => 'UTC',
            'timeliness_status' => $late->timeliness_status->value,
            'days_late' => $late->days_late,
        ];
        $this->assertSame(hash('sha256', json_encode($timelinessPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), $late->timeliness_fingerprint);

        DB::table('third_party_collaboration_request_closures')->where('id', $late->id)->update([
            'response_recorded_at' => null, 'timeliness_status' => null, 'days_late' => null,
            'calendar_timezone' => null, 'timeliness_fingerprint' => null,
        ]);
        $migration = require database_path('migrations/2026_08_25_020000_add_timeliness_to_third_party_collaboration_request_closures.php');
        $migration->up();
        $this->assertDatabaseHas('third_party_collaboration_request_closures', [
            'id' => $late->id, 'response_recorded_at' => '2026-08-27 00:00:01', 'timeliness_status' => 'late',
            'days_late' => 1, 'calendar_timezone' => 'UTC', 'timeliness_fingerprint' => $late->timeliness_fingerprint,
        ]);
        $recovered = $late->fresh();
        $recoveredTimelinessPayload = [
            'accepted_event_id' => $recovered->accepted_event_id,
            'response_recorded_at' => $recovered->response_recorded_at->toIso8601String(),
            'due_context' => [
                'due_at' => $recovered->due_context['due_at'], 'fingerprint' => $recovered->due_context['fingerprint'],
                'extension_id' => $recovered->due_context['extension_id'], 'decision_id' => $recovered->due_context['decision_id'],
            ],
            'calendar_timezone' => $recovered->calendar_timezone,
            'timeliness_status' => $recovered->timeliness_status->value,
            'days_late' => $recovered->days_late,
        ];
        $this->assertSame(hash('sha256', json_encode($recoveredTimelinessPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), $recovered->timeliness_fingerprint);

        $legacyPayload = $onTime->only(['third_party_engagement_collaboration_request_id', 'accepted_event_id', 'request_snapshot', 'accepted_event_snapshot', 'recipient_context', 'due_context', 'escalation_snapshot', 'summary', 'closed_by', 'actor_snapshot']);
        $legacyPayload['closed_at'] = $onTime->closed_at->toIso8601String();
        $legacyFingerprint = hash('sha256', json_encode($legacyPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        DB::table('third_party_collaboration_request_closures')->where('id', $onTime->id)->update([
            'fingerprint' => $legacyFingerprint, 'fingerprint_version' => null,
            'response_recorded_at' => null, 'timeliness_status' => null, 'days_late' => null,
            'calendar_timezone' => null, 'timeliness_fingerprint' => null,
        ]);
        $migration->up();
        $legacy = $onTime->fresh();
        $this->assertSame('closure/v1', $legacy->fingerprint_version);
        $this->assertSame($legacyFingerprint, $legacy->fingerprint);
        $reconstructedLegacy = $legacy->only(['third_party_engagement_collaboration_request_id', 'accepted_event_id', 'request_snapshot', 'accepted_event_snapshot', 'recipient_context', 'due_context', 'escalation_snapshot', 'summary', 'closed_by', 'actor_snapshot']);
        $reconstructedLegacy['closed_at'] = $legacy->closed_at->toIso8601String();
        $this->assertSame($legacy->fingerprint, hash('sha256', json_encode($reconstructedLegacy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
        $beforeRerun = [
            'response_recorded_at' => $legacy->response_recorded_at->toIso8601String(),
            'timeliness_status' => $legacy->timeliness_status->value,
        ] + $legacy->only(['days_late', 'calendar_timezone', 'timeliness_fingerprint', 'fingerprint_version', 'fingerprint']);
        $migration->up();
        $rerun = $legacy->fresh();
        $afterRerun = [
            'response_recorded_at' => $rerun->response_recorded_at->toIso8601String(),
            'timeliness_status' => $rerun->timeliness_status->value,
        ] + $rerun->only(['days_late', 'calendar_timezone', 'timeliness_fingerprint', 'fingerprint_version', 'fingerprint']);
        $this->assertSame($beforeRerun, $afterRerun);
        $migration->down();
        $this->assertDatabaseHas('third_party_collaboration_request_closures', ['id' => $late->id, 'timeliness_status' => 'late']);
        Carbon::setTestNow();
    }
}
