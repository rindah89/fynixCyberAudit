<?php

namespace App\ThirdPartyRisk;

use App\Access\FileAccess;
use App\Enums\ThirdPartyCollaborationCategory;
use App\Enums\ThirdPartyCollaborationStatus;
use App\Enums\ThirdPartyEngagementStatus;
use App\Models\ThirdPartyEngagement;
use App\Models\ThirdPartyEngagementCollaborationEvent;
use App\Models\ThirdPartyEngagementCollaborationEvidence;
use App\Models\ThirdPartyEngagementCollaborationRequest;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorUser;
use App\Services\GovernedVendorDocumentSnapshotter;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ThirdPartyEngagementCollaborationManager
{
    public function open(User $actor, ThirdPartyEngagement $engagement, array $data): ThirdPartyEngagementCollaborationRequest
    {
        $this->assertManager($actor);

        return DB::transaction(function () use ($actor, $engagement, $data): ThirdPartyEngagementCollaborationRequest {
            $locked = $this->lockEngagement($engagement);
            $this->assertManager($actor);
            $data = Validator::make($data, self::openRules())->validate();
            $this->assertCollaborativeState($locked);
            if ($locked->collaborationRequests()->count() >= 100) {
                throw ValidationException::withMessages(['engagement' => 'An engagement is limited to 100 collaboration requests.']);
            }
            $recipient = VendorUser::query()->where('vendor_id', $locked->vendor_id)->whereNull('deleted_at')->lockForUpdate()->find($data['recipient_vendor_user_id']);
            abort_unless($recipient?->hasPassword(), 403);
            $due = Carbon::parse($data['due_at'])->toDateString();
            if ($due < today()->toDateString()) {
                throw ValidationException::withMessages(['due_at' => 'The collaboration due date must be current.']);
            }
            $at = now()->startOfSecond();
            $payload = ['third_party_engagement_id' => $locked->id, 'version' => ((int) $locked->collaborationRequests()->max('version')) + 1,
                'category' => ThirdPartyCollaborationCategory::from($data['category'])->value, 'subject' => $data['subject'], 'request_text' => $data['request_text'],
                'recipient_vendor_user_id' => $recipient->id, 'due_at' => $due, 'engagement_snapshot' => $this->engagementSnapshot($locked),
                'recipient_snapshot' => $this->vendorActorSnapshot($recipient), 'opened_by' => $actor->id, 'opened_at' => $at->toIso8601String()];
            $request = ThirdPartyEngagementCollaborationRequest::query()->create($payload + ['fingerprint' => $this->fingerprint($payload)]);
            $this->appendEvent($request, ThirdPartyCollaborationStatus::Requested, $actor, null, null, 'Collaboration request opened.', $at);

            return $request->load(['recipient:id,vendor_id,name,email', 'opener:id,name,email', 'events']);
        }, 3);
    }

    public function respond(VendorUser $actor, ThirdPartyEngagementCollaborationRequest $request, array $data): ThirdPartyEngagementCollaborationEvent
    {
        $batch = Str::uuid()->toString();
        $retainedCopies = [];
        $snapshotter = app(GovernedVendorDocumentSnapshotter::class);
        try {
            return DB::transaction(function () use ($actor, $request, $data, $batch, &$retainedCopies, $snapshotter): ThirdPartyEngagementCollaborationEvent {
                [$locked, $engagement] = $this->lockRequest($request);
                $reassignments = $locked->reassignments()->orderBy('version')->lockForUpdate()->get();
                $recipientContext = $locked->setRelation('reassignments', $reassignments)->currentRecipientContext();
                $lockedActor = VendorUser::query()->whereNull('deleted_at')->lockForUpdate()->find($actor->id);
                abort_unless($lockedActor?->hasPassword() && $lockedActor->id === $recipientContext['recipient_vendor_user_id'] && $lockedActor->vendor_id === $engagement->vendor_id, 403);
                $data = Validator::make($data, self::responseRules())->validate();
                $this->assertCollaborativeState($engagement);
                $latest = $this->latestEvent($locked);
                if ($locked->cancellation()->lockForUpdate()->exists()) {
                    throw ValidationException::withMessages(['request' => 'A cancelled collaboration request is terminal.']);
                }
                if (! in_array($latest->status, [ThirdPartyCollaborationStatus::Requested, ThirdPartyCollaborationStatus::FollowUp], true)) {
                    throw ValidationException::withMessages(['request' => 'This collaboration request is not awaiting a provider response.']);
                }
                $manifest = ($data['vendor_document_ids'] ?? []) === [] ? [] : $snapshotter->snapshot($data['vendor_document_ids'], $lockedActor, $engagement->vendor_id, $batch, $retainedCopies);
                $event = $this->appendEvent($locked, ThirdPartyCollaborationStatus::Responded, $lockedActor, $data['response_text'], $data['source_reference'] ?? null, null, now()->startOfSecond(), $manifest);
                foreach ($manifest as $snapshot) {
                    ThirdPartyEngagementCollaborationEvidence::query()->create($snapshot + ['third_party_engagement_collaboration_event_id' => $event->id, 'linked_at' => $event->recorded_at]);
                }

                return $event->load('evidence.document');
            }, 3);
        } catch (\Throwable $exception) {
            $snapshotter->cleanup($retainedCopies);
            throw $exception;
        }
    }

    public function decide(User $actor, ThirdPartyEngagementCollaborationRequest $request, array $data): ThirdPartyEngagementCollaborationEvent
    {
        $this->assertManager($actor);

        return DB::transaction(function () use ($actor, $request, $data): ThirdPartyEngagementCollaborationEvent {
            [$locked, $engagement] = $this->lockRequest($request);
            $this->assertManager($actor);
            $data = Validator::make($data, self::decisionRules())->validate();
            $this->assertCollaborativeState($engagement);
            abort_if($actor->id === $locked->opened_by, 403, 'Response disposition must be independent from the request opener.');
            $latest = $this->latestEvent($locked);
            $extensions = $locked->extensions()->with('decision')->orderBy('version')->lockForUpdate()->get();
            if ($locked->cancellation()->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['request' => 'A cancelled collaboration request is terminal.']);
            }
            if ($extensions->contains(fn ($extension): bool => $extension->decision === null)) {
                throw ValidationException::withMessages(['request' => 'The pending due-date extension requires a decision before response disposition.']);
            }
            $decision = ThirdPartyCollaborationStatus::from($data['decision']);
            if ($latest->status !== ThirdPartyCollaborationStatus::Responded || ! in_array($decision, [ThirdPartyCollaborationStatus::Accepted, ThirdPartyCollaborationStatus::FollowUp], true)) {
                throw ValidationException::withMessages(['decision' => 'A provider response can be accepted or returned for follow-up.']);
            }

            return $this->appendEvent($locked, $decision, $actor, null, null, $data['summary'], now()->startOfSecond());
        }, 3);
    }

    public function findRequest(int $id): ThirdPartyEngagementCollaborationRequest
    {
        return ThirdPartyEngagementCollaborationRequest::query()->findOrFail($id);
    }

    /** @param Collection<int, ThirdPartyEngagementCollaborationRequest> $requests @return Collection<int, ThirdPartyEngagementCollaborationRequest> */
    public function visibleRequests(Collection $requests, User $actor): Collection
    {
        return $requests->map(function (ThirdPartyEngagementCollaborationRequest $request) use ($actor): ThirdPartyEngagementCollaborationRequest {
            $visible = clone $request;
            $visibleEvents = $request->events->map(function (ThirdPartyEngagementCollaborationEvent $event) use ($actor): ThirdPartyEngagementCollaborationEvent {
                $copy = clone $event;
                $copy->setRelation('evidence', $event->evidence->filter(function (ThirdPartyEngagementCollaborationEvidence $evidence) use ($actor): bool {
                    $document = $evidence->currentDocument();

                    return $document !== null && ! $document->trashed() && app(FileAccess::class)->canDownloadVendorDocument($actor, $document);
                })->values());

                return $copy;
            });
            $visible->setRelation('events', $visibleEvents);
            if ($request->relationLoaded('closure') && $request->closure !== null) {
                $closure = clone $request->closure;
                $snapshot = $closure->accepted_event_snapshot;
                $responseId = data_get($snapshot, 'response.id');
                $visibleResponse = $visibleEvents->firstWhere('id', $responseId);
                $authorizedDocumentIds = $visibleResponse?->evidence?->pluck('vendor_document_id')->all() ?? [];
                $snapshot['response']['evidence_manifest'] = collect(data_get($snapshot, 'response.evidence_manifest', []))
                    ->filter(fn (array $item): bool => in_array($item['vendor_document_id'] ?? null, $authorizedDocumentIds, true))->values()->all();
                $closure->accepted_event_snapshot = $snapshot;
                $visible->setRelation('closure', $closure);
            }

            return $visible;
        });
    }

    public static function openRules(): array
    {
        return ['category' => ['required', Rule::enum(ThirdPartyCollaborationCategory::class)], 'subject' => 'required|string|max:255', 'request_text' => 'required|string|max:30000',
            'recipient_vendor_user_id' => 'required|integer', 'due_at' => 'required|date_format:Y-m-d', 'version' => 'prohibited', 'fingerprint' => 'prohibited'];
    }

    public static function responseRules(): array
    {
        return ['response_text' => 'required|string|max:30000', 'source_reference' => 'nullable|string|max:255', 'vendor_document_ids' => 'array|max:20', 'vendor_document_ids.*' => 'integer|distinct', 'status' => 'prohibited', 'actor_id' => 'prohibited', 'fingerprint' => 'prohibited'];
    }

    public static function decisionRules(): array
    {
        return ['decision' => ['required', Rule::in([ThirdPartyCollaborationStatus::Accepted->value, ThirdPartyCollaborationStatus::FollowUp->value])], 'summary' => 'required|string|max:30000',
            'status' => 'prohibited', 'actor_id' => 'prohibited', 'fingerprint' => 'prohibited'];
    }

    private function lockEngagement(ThirdPartyEngagement $engagement): ThirdPartyEngagement
    {
        $vendorId = ThirdPartyEngagement::query()->whereKey($engagement->id)->value('vendor_id');
        $vendor = Vendor::withTrashed()->lockForUpdate()->findOrFail($vendorId);

        return ThirdPartyEngagement::query()->where('vendor_id', $vendor->id)->lockForUpdate()->findOrFail($engagement->id);
    }

    private function lockRequest(ThirdPartyEngagementCollaborationRequest $request): array
    {
        $engagementId = ThirdPartyEngagementCollaborationRequest::query()->whereKey($request->id)->value('third_party_engagement_id');
        $engagement = $this->lockEngagement(ThirdPartyEngagement::query()->findOrFail($engagementId));
        $locked = ThirdPartyEngagementCollaborationRequest::query()->where('third_party_engagement_id', $engagement->id)->lockForUpdate()->findOrFail($request->id);

        return [$locked, $engagement];
    }

    private function latestEvent(ThirdPartyEngagementCollaborationRequest $request): ThirdPartyEngagementCollaborationEvent
    {
        return ThirdPartyEngagementCollaborationEvent::query()->where('third_party_engagement_collaboration_request_id', $request->id)->orderByDesc('version')->lockForUpdate()->firstOrFail();
    }

    private function appendEvent(ThirdPartyEngagementCollaborationRequest $request, ThirdPartyCollaborationStatus $status, User|VendorUser $actor, ?string $response, ?string $source, ?string $summary, Carbon $at, array $manifest = []): ThirdPartyEngagementCollaborationEvent
    {
        if ($request->events()->count() >= 20) {
            throw ValidationException::withMessages(['request' => 'A collaboration request is limited to 20 retained events.']);
        }
        $actorType = $actor instanceof VendorUser ? 'vendor_user' : 'user';
        $actorSnapshot = $actor instanceof VendorUser ? $this->vendorActorSnapshot($actor) : Arr::only($actor->toArray(), ['id', 'name', 'email']);
        $payload = ['third_party_engagement_collaboration_request_id' => $request->id, 'version' => ((int) $request->events()->max('version')) + 1,
            'status' => $status->value, 'response_text' => $response, 'source_reference' => $source, 'summary' => $summary, 'actor_type' => $actorType,
            'actor_id' => $actor->id, 'actor_snapshot' => $actorSnapshot, 'request_snapshot' => $this->requestSnapshot($request), 'evidence_manifest' => $manifest, 'recorded_at' => $at->toIso8601String()];

        return ThirdPartyEngagementCollaborationEvent::query()->create($payload + ['fingerprint' => $this->fingerprint($payload)]);
    }

    private function assertCollaborativeState(ThirdPartyEngagement $engagement): void
    {
        if (! in_array($engagement->status, [ThirdPartyEngagementStatus::DueDiligence, ThirdPartyEngagementStatus::Approved, ThirdPartyEngagementStatus::Active, ThirdPartyEngagementStatus::RenewalReview], true)) {
            throw ValidationException::withMessages(['engagement' => 'Collaboration is available only during a governed non-terminal engagement.']);
        }
    }

    private function engagementSnapshot(ThirdPartyEngagement $engagement): array
    {
        return Arr::only($engagement->toArray(), ['id', 'vendor_id', 'code', 'name', 'service_description', 'business_owner_id', 'criticality', 'data_access', 'status', 'term_start_at', 'term_end_at', 'next_review_at', 'approval_snapshot', 'onboarding_readiness_snapshot', 'offboarding_readiness_snapshot', 'governed_at']);
    }

    private function vendorActorSnapshot(VendorUser $actor): array
    {
        return Arr::only($actor->toArray(), ['id', 'vendor_id', 'name', 'email', 'is_primary']);
    }

    private function requestSnapshot(ThirdPartyEngagementCollaborationRequest $request): array
    {
        return Arr::only($request->attributesToArray(), ['id', 'third_party_engagement_id', 'version', 'category', 'subject', 'request_text', 'recipient_vendor_user_id', 'due_at', 'engagement_snapshot', 'recipient_snapshot', 'opened_by', 'opened_at', 'fingerprint'])
            + ['current_recipient_context' => $request->currentRecipientContext()];
    }

    private function assertManager(User $actor): void
    {
        abort_unless($actor->isSuperAdmin() || $actor->can('Manage Third Party Risk'), 403);
    }

    private function fingerprint(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
