<?php

namespace Tests\Feature;

use App\Access\DataRequestFulfillment;
use App\Enums\ResponseStatus;
use App\Filament\Resources\DataRequestResource;
use App\Mail\EvidenceRequestMail;
use App\Models\DataRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DataRequestFulfillmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_creates_pending_response(): void
    {
        $request = DataRequest::factory()->pending()->create();

        $response = app(DataRequestFulfillment::class)->open($request, '2026-09-01');

        $this->assertSame(ResponseStatus::PENDING, $response->status);
        $this->assertSame($request->created_by_id, $response->requester_id);
        $this->assertSame($request->assigned_to_id, $response->requestee_id);
        $this->assertTrue($response->due_at->isSameDay('2026-09-01'));
        $this->assertTrue($request->responses()->whereKey($response->id)->exists());
    }

    public function test_resource_create_responses_uses_fulfillment(): void
    {
        $request = DataRequest::factory()->pending()->create();

        DataRequestResource::createResponses($request, '2026-09-02');

        $this->assertSame(1, $request->responses()->count());
        $this->assertSame(ResponseStatus::PENDING, $request->responses()->first()->status);
    }

    public function test_notify_sends_evidence_request_mail(): void
    {
        Mail::fake();

        $assignee = User::factory()->create(['email' => 'assignee@example.com']);

        app(DataRequestFulfillment::class)->notify($assignee);

        Mail::assertSent(EvidenceRequestMail::class, function (EvidenceRequestMail $mail) use ($assignee) {
            return $mail->email === $assignee->email && $mail->name === $assignee->name;
        });
    }

    public function test_respond_accept_reject_and_reassign(): void
    {
        Mail::fake();

        $request = DataRequest::factory()->pending()->create();
        $fulfillment = app(DataRequestFulfillment::class);
        $response = $fulfillment->open($request);

        $fulfillment->respond($response, 'Here is the evidence.');
        $this->assertSame(ResponseStatus::RESPONDED, $response->fresh()->status);
        $this->assertSame('Here is the evidence.', $response->fresh()->response);

        $fulfillment->accept($response);
        $this->assertSame(ResponseStatus::ACCEPTED, $response->fresh()->status);

        $fulfillment->reject($response);
        $this->assertSame(ResponseStatus::REJECTED, $response->fresh()->status);

        $newAssignee = User::factory()->create();
        $fulfillment->reassign($response, $newAssignee, true);

        $this->assertSame($newAssignee->id, $response->fresh()->requestee_id);
        $this->assertSame(ResponseStatus::PENDING, $response->fresh()->status);
        $this->assertSame($newAssignee->id, $request->fresh()->assigned_to_id);
        Mail::assertSent(EvidenceRequestMail::class);
    }
}
