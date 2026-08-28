<?php

namespace App\Suite;

use App\Models\GovernanceStatement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class GovernanceEvidenceController
{
    public function store(Request $request, GovernanceEvidenceService $service): JsonResponse
    {
        $raw = $request->getContent();
        if (strlen($raw) > 65536) {
            return response()->json(['outcome' => 'payload too large'], 413);
        }
        $headers = [];
        foreach (['x-fynix-signature', 'x-fynix-timestamp', 'x-fynix-event', 'x-fynix-source', 'x-fynix-webhook-id', 'x-fynix-delivery-id'] as $name) {
            $headers[$name] = (string) $request->header($name, '');
        }
        $source = $headers['x-fynix-source'];
        if (! in_array($source, config('data_governance.required_sources', []), true)) {
            return response()->json(['outcome' => 'unsupported source'], 400);
        }
        $binding = config('data_governance.bindings.'.$source);
        if (! is_array($binding) || ! ($binding['enabled'] ?? false) || empty($binding['secret']) || empty($binding['tenant_id']) || empty($binding['webhook_id'])) {
            return response()->json(['outcome' => 'binding disabled'], 503);
        }
        if (! hash_equals((string) ($binding['webhook_id'] ?? ''), $headers['x-fynix-webhook-id'])) {
            return response()->json(['outcome' => 'binding disabled'], 503);
        }
        if (! SuiteEnvelope::verify([(string) ($binding['secret'] ?? '')], $headers, $raw, time(), (int) ($binding['replay_tolerance'] ?? 300))) {
            return response()->json(['outcome' => 'invalid signature'], 401);
        }

        $envelope = json_decode($raw, true);
        if (! is_array($envelope)) {
            return response()->json(['outcome' => 'invalid json'], 400);
        }
        $validator = Validator::make($envelope, [
            'event_type' => ['required', 'in:governance.evidence.reported'],
            'tenant_id' => ['required', 'string', 'max:128'],
            'entity_type' => ['required', 'in:governance_statement'],
            'entity_id' => ['required', 'uuid'],
            'occurred_at' => ['required', 'date'],
            'payload' => ['required', 'array'],
            'payload.schema_version' => ['required', 'in:'.config('data_governance.schema_version')],
            'payload.period_start' => ['required', 'date'],
            'payload.period_end' => ['required', 'date', 'after_or_equal:payload.period_start'],
            'payload.controls' => ['required', 'array', 'size:'.count(config('data_governance.controls', []))],
            'payload.controls.*.control_id' => ['required', 'string', 'distinct'],
            'payload.controls.*.status' => ['required', 'in:effective,partially_effective,ineffective,not_applicable,unknown'],
            'payload.controls.*.observed_at' => ['required', 'date'],
            'payload.controls.*.summary' => ['nullable', 'string', 'max:2000'],
            'payload.controls.*.evidence_refs' => ['required', 'array', 'max:50'],
            'payload.controls.*.evidence_refs.*' => ['string', 'max:500'],
            'payload.controls.*.metrics' => ['required', 'array'],
        ]);
        if ($validator->fails() || $headers['x-fynix-event'] !== ($envelope['event_type'] ?? null)) {
            return response()->json(['outcome' => 'invalid statement', 'errors' => $validator->errors()], 422);
        }
        if (! hash_equals((string) ($binding['tenant_id'] ?? ''), (string) $envelope['tenant_id'])) {
            return response()->json(['outcome' => 'tenant mismatch'], 403);
        }
        if (GovernanceStatement::query()->where('delivery_id', $headers['x-fynix-delivery-id'])->exists()) {
            return response()->json(['outcome' => 'duplicate ignored']);
        }
        if (GovernanceStatement::query()->where('statement_id', $envelope['entity_id'])->exists()) {
            return response()->json(['outcome' => 'statement conflict'], 409);
        }

        $statement = $service->record($envelope, $source, $headers['x-fynix-delivery-id'], $raw);

        return response()->json(['outcome' => 'recorded', 'statement_id' => $statement->statement_id], 201);
    }

    public function readiness(GovernanceOversightService $oversight): JsonResponse
    {
        return response()->json($oversight->report(false));
    }

    public function oversight(Request $request, GovernanceOversightService $oversight): JsonResponse
    {
        abort_unless($request->user()?->can('View Governance Oversight'), 403);

        return response()->json($oversight->report());
    }
}
