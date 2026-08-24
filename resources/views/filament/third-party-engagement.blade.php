<div class="space-y-4 text-sm">
    <dl class="grid grid-cols-1 gap-3 md:grid-cols-3">
        <div><dt class="font-medium">Code</dt><dd>{{ $engagement->code }}</dd></div>
        <div><dt class="font-medium">Status</dt><dd>{{ $engagement->status->getLabel() }}</dd></div>
        <div><dt class="font-medium">Business owner</dt><dd>{{ $engagement->businessOwner?->name }}</dd></div>
        <div><dt class="font-medium">Criticality</dt><dd>{{ ucfirst($engagement->criticality) }}</dd></div>
        <div><dt class="font-medium">Term</dt><dd>{{ $engagement->term_start_at?->toDateString() }} – {{ $engagement->term_end_at?->toDateString() }}</dd></div>
        <div><dt class="font-medium">Next review</dt><dd>{{ $engagement->next_review_at?->toDateString() }}</dd></div>
    </dl>
    <div><div class="font-medium">Service description</div><div class="whitespace-pre-wrap">{{ $engagement->service_description }}</div></div>
    <div class="space-y-2">
        <div class="font-medium">Append-only decisions</div>
        @foreach ($engagement->events as $event)
            <div class="rounded-lg border p-3">
                <div>v{{ $event->version }} · {{ $event->to_status->getLabel() }} · {{ $event->actor?->name }} · {{ $event->recorded_at?->toDayDateTimeString() }}</div>
                <div class="mt-1 whitespace-pre-wrap">{{ $event->summary }}</div>
                <div class="mt-1 break-all font-mono text-xs">{{ $event->fingerprint }}</div>
            </div>
        @endforeach
    </div>
    <div class="space-y-2">
        <div class="font-medium">Structured due-diligence review history</div>
        @forelse ($engagement->dueDiligenceReviews->sortBy('version') as $review)
            <div class="rounded-lg border p-3">
                <div>v{{ $review->version }} · {{ $review->decision->getLabel() }} · {{ $review->reviewer?->name }} · {{ $review->reviewed_at?->toDayDateTimeString() }}</div>
                <dl class="mt-2 grid grid-cols-2 gap-2 md:grid-cols-5">
                    <div><dt class="font-medium">Cybersecurity</dt><dd>{{ $review->cybersecurity_rating }}/5</dd></div>
                    <div><dt class="font-medium">Privacy</dt><dd>{{ $review->privacy_rating }}/5</dd></div>
                    <div><dt class="font-medium">Resilience</dt><dd>{{ $review->resilience_rating }}/5</dd></div>
                    <div><dt class="font-medium">Compliance</dt><dd>{{ $review->compliance_rating }}/5</dd></div>
                    <div><dt class="font-medium">Financial</dt><dd>{{ $review->financial_rating }}/5</dd></div>
                </dl>
                <div class="mt-2 whitespace-pre-wrap"><span class="font-medium">Findings:</span> {{ $review->findings_summary }}</div>
                <div class="whitespace-pre-wrap"><span class="font-medium">Conditions:</span> {{ $review->conditions ?: 'None recorded' }}</div>
                <div class="whitespace-pre-wrap"><span class="font-medium">Rationale:</span> {{ $review->rationale }}</div>
                <div>Next review: {{ $review->next_review_at?->toDateString() }}</div>
                @if ($review->survey_snapshot)
                    <details class="mt-2"><summary class="font-medium">Authorized survey evidence snapshot</summary><pre class="mt-1 overflow-auto whitespace-pre-wrap text-xs">{{ json_encode($review->survey_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre></details>
                @endif
                @if ($review->document_snapshots)
                    <details class="mt-2"><summary class="font-medium">Authorized document metadata snapshots</summary><pre class="mt-1 overflow-auto whitespace-pre-wrap text-xs">{{ json_encode($review->document_snapshots, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre></details>
                @endif
                <details class="mt-2"><summary class="font-medium">Engagement and vendor-risk approval snapshots</summary><pre class="mt-1 overflow-auto whitespace-pre-wrap text-xs">{{ json_encode(['engagement' => $review->engagement_snapshot, 'risk_approval' => $review->risk_approval_snapshot], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre></details>
                <div class="mt-1 break-all font-mono text-xs">{{ $review->fingerprint }}</div>
            </div>
        @empty
            <div>No structured due-diligence review has been recorded.</div>
        @endforelse
    </div>
    <div class="space-y-2">
        <div class="font-medium">Contract-risk review history</div>
        @forelse ($engagement->contractRiskReviews->sortBy('version') as $review)
            <div class="rounded-lg border p-3">
                <div>v{{ $review->version }} · {{ $review->decision->getLabel() }} · {{ $review->contract_reference }} · {{ $review->reviewer?->name }}</div>
                <div class="mt-1">{{ $review->agreement_type }} · {{ $review->effective_at?->toDateString() }} – {{ $review->expires_at?->toDateString() }}</div>
                @if ($review->proposed_term_end_at)
                    <div class="mt-1">Proposed renewed term end: {{ $review->proposed_term_end_at->toDateString() }} · next review: {{ $review->proposed_next_review_at?->toDateString() }}</div>
                @endif
                <dl class="mt-2 grid grid-cols-2 gap-2 md:grid-cols-4">
                    <div><dt class="font-medium">Confidentiality</dt><dd>{{ $review->confidentiality_terms ? 'Present' : 'Absent' }}</dd></div>
                    <div><dt class="font-medium">Data protection</dt><dd>{{ $review->data_protection_terms ? 'Present' : 'Absent' }}</dd></div>
                    <div><dt class="font-medium">Incident notification</dt><dd>{{ $review->incident_notification_terms ? 'Present' : 'Absent' }}</dd></div>
                    <div><dt class="font-medium">Audit rights</dt><dd>{{ $review->audit_rights ? 'Present' : 'Absent' }}</dd></div>
                    <div><dt class="font-medium">Subcontractor controls</dt><dd>{{ $review->subcontractor_controls ? 'Present' : 'Absent' }}</dd></div>
                    <div><dt class="font-medium">Business continuity</dt><dd>{{ $review->business_continuity_terms ? 'Present' : 'Absent' }}</dd></div>
                    <div><dt class="font-medium">Termination assistance</dt><dd>{{ $review->termination_assistance ? 'Present' : 'Absent' }}</dd></div>
                </dl>
                <div class="mt-2"><span class="font-medium">Service levels:</span> {{ $review->service_level_summary }}</div>
                <div><span class="font-medium">Liability:</span> {{ $review->liability_summary }}</div>
                <div><span class="font-medium">Exit terms:</span> {{ $review->exit_terms_summary }}</div>
                <div><span class="font-medium">Exceptions:</span> {{ $review->exceptions_summary ?: 'None recorded' }}</div>
                <div><span class="font-medium">Conditions:</span> {{ $review->conditions ?: 'None recorded' }}</div>
                <div class="mt-1 whitespace-pre-wrap">{{ $review->rationale }}</div>
                <div class="mt-2 break-all"><span class="font-medium">Source engagement event:</span> <span class="font-mono text-xs">{{ $review->engagement_event_fingerprint }}</span></div>
                <details class="mt-2"><summary class="font-medium">Engagement snapshot</summary><pre class="mt-1 overflow-auto whitespace-pre-wrap text-xs">{{ json_encode($review->engagement_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre></details>
                <details class="mt-2"><summary class="font-medium">Vendor-risk approval snapshot</summary><pre class="mt-1 overflow-auto whitespace-pre-wrap text-xs">{{ json_encode($review->risk_approval_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre></details>
                <div class="mt-1 break-all font-mono text-xs">{{ $review->fingerprint }}</div>
            </div>
        @empty
            <div>No contract-risk review has been recorded.</div>
        @endforelse
    </div>
    <div class="space-y-2">
        <div class="font-medium">Governed onboarding controls and completion history</div>
        @forelse ($engagement->onboardingRequirements->sortBy('version') as $requirement)
            <div class="rounded-lg border p-3">
                <div>Control v{{ $requirement->version }} · {{ $requirement->category->getLabel() }} · {{ $requirement->title }} · {{ $requirement->required ? 'Required' : 'Optional' }}</div>
                <div>Owner: {{ $requirement->owner?->name }} · due {{ $requirement->due_at?->toDateString() }} · defined by {{ $requirement->definer?->name }}</div>
                <div class="mt-1 whitespace-pre-wrap"><span class="font-medium">Acceptance criteria:</span> {{ $requirement->acceptance_criteria }}</div>
                @forelse ($requirement->completions->sortBy('version') as $completion)
                    <div class="mt-2 rounded-lg border p-2">
                        <div>Completion v{{ $completion->version }} · {{ $completion->completer?->name }} · {{ $completion->completed_at?->toDayDateTimeString() }}</div>
                        <div class="whitespace-pre-wrap">{{ $completion->completion_summary }}</div><div>Source reference: {{ $completion->source_reference ?: 'None recorded' }}</div>
                        <div class="break-all font-mono text-xs">{{ $completion->fingerprint }}</div>
                    </div>
                @empty <div class="mt-2">No completion evidence recorded.</div> @endforelse
                <div class="mt-1 break-all font-mono text-xs">{{ $requirement->fingerprint }}</div>
            </div>
        @empty <div>No onboarding controls have been defined.</div> @endforelse
    </div>
    <div class="space-y-2">
        <div class="font-medium">Independent onboarding-readiness history</div>
        @forelse ($engagement->onboardingReadinessReviews->sortBy('version') as $review)
            <div class="rounded-lg border p-3">
                <div>v{{ $review->version }} · {{ $review->decision->getLabel() }} · {{ $review->reviewer?->name }} · {{ $review->reviewed_at?->toDayDateTimeString() }}</div>
                <div class="mt-1 whitespace-pre-wrap">{{ $review->summary }}</div><div>Conditions: {{ $review->conditions ?: 'None recorded' }} · next review {{ $review->next_review_at?->toDateString() }}</div>
                <details class="mt-2"><summary class="font-medium">Retained onboarding evidence</summary><pre class="mt-1 overflow-auto whitespace-pre-wrap text-xs">{{ json_encode(['engagement' => $review->engagement_snapshot, 'requirements' => $review->requirements_snapshot], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre></details>
                <div class="mt-1 break-all">Engagement event: <span class="font-mono text-xs">{{ $review->engagement_event_fingerprint }}</span></div><div class="break-all">Contract review: <span class="font-mono text-xs">{{ $review->contract_review_fingerprint }}</span></div><div class="break-all font-mono text-xs">{{ $review->fingerprint }}</div>
            </div>
        @empty <div>No onboarding-readiness review has been recorded.</div> @endforelse
    </div>
    <div class="space-y-2">
        <div class="font-medium">Governed offboarding controls and completion history</div>
        @forelse ($engagement->offboardingRequirements->sortBy('version') as $requirement)
            <div class="rounded-lg border p-3">
                <div>Control v{{ $requirement->version }} · {{ $requirement->category->getLabel() }} · {{ $requirement->title }} · {{ $requirement->required ? 'Required' : 'Optional' }}</div>
                <div>Owner: {{ $requirement->owner?->name }} · due {{ $requirement->due_at?->toDateString() }} · defined by {{ $requirement->definer?->name }}</div>
                <div class="mt-1 whitespace-pre-wrap"><span class="font-medium">Acceptance criteria:</span> {{ $requirement->acceptance_criteria }}</div>
                @forelse ($requirement->completions->sortBy('version') as $completion)
                    <div class="mt-2 rounded-lg border p-2"><div>Completion v{{ $completion->version }} · {{ $completion->completer?->name }} · {{ $completion->completed_at?->toDayDateTimeString() }}</div><div class="whitespace-pre-wrap">{{ $completion->completion_summary }}</div><div>Source reference: {{ $completion->source_reference ?: 'None recorded' }}</div><div class="break-all font-mono text-xs">{{ $completion->fingerprint }}</div></div>
                @empty <div class="mt-2">No completion evidence recorded.</div> @endforelse
                <div class="mt-1 break-all font-mono text-xs">{{ $requirement->fingerprint }}</div>
            </div>
        @empty <div>No offboarding controls have been defined.</div> @endforelse
    </div>
    <div class="space-y-2">
        <div class="font-medium">Independent offboarding-readiness history</div>
        @forelse ($engagement->offboardingReadinessReviews->sortBy('version') as $review)
            <div class="rounded-lg border p-3"><div>v{{ $review->version }} · {{ $review->decision->getLabel() }} · {{ $review->reviewer?->name }} · {{ $review->reviewed_at?->toDayDateTimeString() }}</div><div class="mt-1 whitespace-pre-wrap">{{ $review->summary }}</div><div>Conditions: {{ $review->conditions ?: 'None recorded' }}</div><details class="mt-2"><summary class="font-medium">Retained offboarding evidence</summary><pre class="mt-1 overflow-auto whitespace-pre-wrap text-xs">{{ json_encode(['engagement' => $review->engagement_snapshot, 'requirements' => $review->requirements_snapshot], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre></details><div class="mt-1 break-all">Engagement event: <span class="font-mono text-xs">{{ $review->engagement_event_fingerprint }}</span></div><div class="break-all font-mono text-xs">{{ $review->fingerprint }}</div></div>
        @empty <div>No offboarding-readiness review has been recorded.</div> @endforelse
    </div>
    <div class="space-y-2">
        <div class="font-medium">Engagement monitoring evidence</div>
        @forelse ($engagement->monitoringIndicators->sortBy([['code', 'asc'], ['version', 'asc']]) as $indicator)
            <div class="rounded-lg border p-3">
                <div>{{ $indicator->code }} v{{ $indicator->version }} · {{ $indicator->name }} · {{ $indicator->category->getLabel() }} · {{ $indicator->monitoring_status->getLabel() }}</div>
                <div class="mt-1">Owner: {{ $indicator->owner?->name }} · {{ $indicator->unit }} · {{ $indicator->direction->getLabel() }} · every {{ $indicator->frequency_days }} days</div>
                <div>Warning {{ $indicator->warning_threshold }} · Critical {{ $indicator->critical_threshold }}</div>
                <div class="mt-1 whitespace-pre-wrap">{{ $indicator->description }}</div>
                <div><span class="font-medium">Measurement method:</span> {{ $indicator->measurement_method }}</div>
                <details class="mt-2"><summary class="font-medium">Retained engagement, contract, and risk context</summary><pre class="mt-1 overflow-auto whitespace-pre-wrap text-xs">{{ json_encode(['engagement' => $indicator->engagement_snapshot, 'contract_review' => $indicator->contract_review_snapshot, 'risk_approval' => $indicator->risk_approval_snapshot], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre></details>
                <div class="mt-1 break-all font-mono text-xs">{{ $indicator->fingerprint }}</div>
                <div class="mt-2 font-medium">Latest 10 observations</div>
                @foreach ($indicator->latestObservations->sortBy('version') as $observation)
                    <div class="mt-2 rounded-lg border p-2">
                        <div>Observation v{{ $observation->version }} · {{ $observation->status->getLabel() }} · {{ $observation->observed_value }} {{ $indicator->unit }} · {{ $observation->observer?->name }} · {{ $observation->observed_at?->toDayDateTimeString() }}</div>
                        <div>{{ $observation->reason }}</div>
                        <div class="whitespace-pre-wrap">{{ $observation->notes }}</div>
                        <div>Source reference: {{ $observation->source_reference ?: 'None recorded' }}</div>
                        <details class="mt-1"><summary class="font-medium">Observation snapshots</summary><pre class="mt-1 overflow-auto whitespace-pre-wrap text-xs">{{ json_encode(['indicator' => $observation->indicator_snapshot, 'engagement' => $observation->engagement_snapshot, 'contract_review' => $observation->contract_review_snapshot, 'risk_approval' => $observation->risk_approval_snapshot], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre></details>
                        <div class="break-all font-mono text-xs">{{ $observation->fingerprint }}</div>
                    </div>
                @endforeach
            </div>
        @empty
            <div>No engagement monitoring indicator has been defined.</div>
        @endforelse
    </div>
</div>
<div class="mt-4 space-y-2">
    <div class="font-medium">Governed provider collaboration history</div>
    @forelse ($engagement->collaborationRequests->sortBy('version') as $request)
        <div class="rounded-lg border p-3">
            <div>Request v{{ $request->version }} · {{ $request->category->getLabel() }} · {{ $request->subject }}</div>
            <div>Recipient: {{ $request->recipient?->name }} · due {{ $request->due_at?->toDateString() }} · opened by {{ $request->opener?->name }}</div>
            <div class="mt-1 whitespace-pre-wrap">{{ $request->request_text }}</div>
            <details class="mt-2"><summary class="font-medium">Retained request context</summary><pre class="mt-1 overflow-auto whitespace-pre-wrap text-xs">{{ json_encode(['engagement' => $request->engagement_snapshot, 'recipient' => $request->recipient_snapshot], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre></details>
            @foreach ($request->events->sortBy('version') as $event)
                <div class="mt-2 rounded-lg border p-2">
                    <div>Event v{{ $event->version }} · {{ $event->status->getLabel() }} · {{ data_get($event->actor_snapshot, 'name') }} · {{ $event->recorded_at?->toDayDateTimeString() }}</div>
                    @if ($event->response_text)<div class="whitespace-pre-wrap">{{ $event->response_text }}</div>@endif
                    @if ($event->summary)<div class="whitespace-pre-wrap">{{ $event->summary }}</div>@endif
                    <div>Source reference: {{ $event->source_reference ?: 'None recorded' }}</div>
                    @foreach ($event->evidence as $evidence)
                        <div><a class="underline" href="{{ route('third-party-collaboration-evidence.download', $evidence) }}" target="_blank">{{ $evidence->file_name_snapshot }}</a> · {{ $evidence->file_size_snapshot }} bytes · <span class="break-all font-mono text-xs">{{ $evidence->sha256 }}</span></div>
                    @endforeach
                    <div class="break-all font-mono text-xs">{{ $event->fingerprint }}</div>
                </div>
            @endforeach
            @foreach ($request->reminders->sortBy('delivered_at') as $reminder)
                <div class="mt-2 rounded-lg border p-2">
                    <div>{{ $reminder->type->getLabel() }} reminder · {{ $reminder->channel }} · attempted {{ $reminder->attempted_at?->toDayDateTimeString() }} · delivered {{ $reminder->delivered_at?->toDayDateTimeString() }}</div>
                    <div class="break-all">Notification: <span class="font-mono text-xs">{{ $reminder->notification_id }}</span></div>
                    <details class="mt-1"><summary class="font-medium">Retained recipient, request, and latest-event snapshots</summary><pre class="mt-1 overflow-auto whitespace-pre-wrap text-xs">{{ json_encode(['recipient' => $reminder->recipient_snapshot, 'request' => $reminder->request_snapshot, 'event' => $reminder->event_snapshot], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre></details>
                    <div class="break-all font-mono text-xs">{{ $reminder->fingerprint }}</div>
                </div>
            @endforeach
            <div class="mt-1 break-all font-mono text-xs">{{ $request->fingerprint }}</div>
        </div>
    @empty
        <div>No governed provider collaboration has been recorded.</div>
    @endforelse
</div>
