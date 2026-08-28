<?php

namespace App\Suite;

use App\Models\LegalHold;
use App\Models\PrivacyRequest;
use App\Models\RetentionPolicy;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class GovernanceControlController
{
    public function store(Request $request, DataGovernanceControlService $service): JsonResponse
    {
        $raw = $request->getContent();
        if (strlen($raw) > 32768) {
            return response()->json(['outcome' => 'payload too large'], 413);
        }
        $headers = collect(['signature', 'timestamp', 'event', 'source', 'webhook-id', 'delivery-id'])
            ->mapWithKeys(fn (string $name): array => [$name => (string) $request->header('X-Fynix-'.$name, '')]);
        $source = $headers['source'];
        $binding = config('data_governance.bindings.'.$source);
        if (! is_array($binding) || ! ($binding['enabled'] ?? false) || ! hash_equals((string) ($binding['webhook_id'] ?? ''), $headers['webhook-id'])) {
            return response()->json(['outcome' => 'binding disabled'], 503);
        }
        $envelopeHeaders = collect($headers)->mapWithKeys(fn (string $value, string $key): array => ['x-fynix-'.$key => $value])->all();
        if ($headers['event'] !== 'governance.control.commanded' || ! SuiteEnvelope::verify([(string) ($binding['secret'] ?? '')], $envelopeHeaders, $raw, time(), (int) ($binding['replay_tolerance'] ?? 300))) {
            return response()->json(['outcome' => 'invalid signature'], 401);
        }
        if (DB::table('governance_control_deliveries')->where('delivery_id', $headers['delivery-id'])->exists()) {
            return response()->json(['outcome' => 'duplicate ignored']);
        }
        $body = json_decode($raw, true);
        $validator = Validator::make(is_array($body) ? $body : [], [
            'tenant_id' => ['required', 'string', 'max:128'],
            'command' => ['required', 'in:privacy_request.open,privacy_request.close,retention_policy.define,retention.disposition.record,legal_hold.place,legal_hold.release,processor.register,recovery_evidence.record,audit_event.record'],
            'payload' => ['required', 'array'],
        ]);
        if ($validator->fails()) {
            return response()->json(['outcome' => 'invalid command', 'errors' => $validator->errors()], 422);
        }
        if (! hash_equals((string) $binding['tenant_id'], (string) $body['tenant_id'])) {
            return response()->json(['outcome' => 'tenant mismatch'], 403);
        }
        $payloadValidator = Validator::make($body['payload'], match ($body['command']) {
            'privacy_request.open' => ['subject_ref' => ['required', 'uuid'], 'right' => ['required', 'string'], 'lawful_basis' => ['required', 'string', 'max:64'], 'requested_at' => ['nullable', 'date']],
            'privacy_request.close' => ['privacy_request_id' => ['required', 'integer', 'min:1'], 'evidence_ref' => ['required', 'regex:/^(urn:fynix:|evidence:\/\/)[A-Za-z0-9._:\/-]+$/', 'max:2048'], 'evidence_sha256' => ['required', 'regex:/^[a-f0-9]{64}$/']],
            'retention_policy.define' => ['record_class' => ['required', 'string', 'max:128'], 'retention_days' => ['required', 'integer', 'min:1'], 'disposition_action' => ['required', 'in:delete,anonymize,archive']],
            'retention.disposition.record' => ['retention_policy_id' => ['required', 'integer', 'min:1'], 'record_ref' => ['required', 'uuid'], 'record_created_at' => ['required', 'date'], 'action' => ['required', 'in:delete,anonymize,archive'], 'evidence_ref' => ['required', 'regex:/^(urn:fynix:|evidence:\/\/)[A-Za-z0-9._:\/-]+$/', 'max:2048'], 'evidence_sha256' => ['required', 'regex:/^[a-f0-9]{64}$/']],
            'legal_hold.place' => ['retention_policy_id' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:1000'], 'record_ref' => ['nullable', 'uuid'], 'source_hold_ref' => ['nullable', 'uuid']],
            'legal_hold.release' => ['legal_hold_id' => ['required', 'integer', 'min:1']],
            'processor.register' => ['name' => ['required', 'string', 'max:255'], 'purpose' => ['required', 'string'], 'data_categories' => ['required', 'array', 'min:1'], 'processing_countries' => ['required', 'array'], 'transfer_mechanism' => ['nullable', 'string'], 'agreement_owner' => ['required', 'string'], 'agreement_evidence_ref' => ['required', 'regex:/^(urn:fynix:|evidence:\/\/)[A-Za-z0-9._:\/-]+$/'], 'agreement_evidence_sha256' => ['required', 'regex:/^[a-f0-9]{64}$/'], 'review_due_at' => ['required', 'date']],
            'recovery_evidence.record' => ['kind' => ['required', 'in:restore_drill'], 'occurred_at' => ['required', 'date', 'before_or_equal:now'], 'outcome' => ['required', 'in:successful'], 'evidence_ref' => ['required', 'regex:/^(urn:fynix:|evidence:\/\/)[A-Za-z0-9._:\/-]+$/', 'max:2048'], 'evidence_sha256' => ['required', 'regex:/^[a-f0-9]{64}$/']],
            'audit_event.record' => ['source_event_ref' => ['required', 'uuid'], 'subject_ref' => ['nullable', 'uuid'], 'action' => ['required', 'regex:/^[a-z0-9_.:-]+$/', 'max:128'], 'outcome' => ['required', 'in:succeeded,denied,failed'], 'correlation_ref' => ['nullable', 'uuid'], 'occurred_at' => ['required', 'date', 'before_or_equal:now']],
        });
        if ($payloadValidator->fails()) {
            return response()->json(['outcome' => 'invalid command', 'errors' => $payloadValidator->errors()], 422);
        }

        try {
            [$resource, $created] = DB::transaction(function () use ($body, $binding, $source, $service, $headers, $raw, $payloadValidator): array {
                $payload = array_merge($payloadValidator->validated(), ['tenant_id' => (string) $binding['tenant_id'], 'source' => $source]);
                $resource = match ($body['command']) {
                    'privacy_request.open' => $service->openPrivacyRequest($payload),
                    'privacy_request.close' => $service->closePrivacyRequest(PrivacyRequest::query()->where(['id' => $payload['privacy_request_id'], 'tenant_id' => $binding['tenant_id'], 'source' => $source])->firstOrFail(), $payload['evidence_ref'], $payload['evidence_sha256']),
                    'retention_policy.define' => $service->defineRetentionPolicy($payload),
                    'retention.disposition.record' => $service->recordDisposition(RetentionPolicy::query()->where(['id' => $payload['retention_policy_id'], 'tenant_id' => $binding['tenant_id'], 'source' => $source])->firstOrFail(), $payload),
                    'legal_hold.place' => $service->placeLegalHold(RetentionPolicy::query()->where(['id' => $payload['retention_policy_id'], 'tenant_id' => $binding['tenant_id'], 'source' => $source])->firstOrFail(), $payload['reason'], $payload['record_ref'] ?? null, $payload['source_hold_ref'] ?? null),
                    'legal_hold.release' => $service->releaseLegalHold(LegalHold::query()->whereKey($payload['legal_hold_id'])->whereHas('retentionPolicy', fn ($query) => $query->where(['tenant_id' => $binding['tenant_id'], 'source' => $source]))->firstOrFail()),
                    'processor.register' => $service->registerProcessor($payload),
                    'recovery_evidence.record' => $service->recordRecoveryEvidence($payload),
                    'audit_event.record' => $service->recordAuditEvent($payload),
                };
                DB::table('governance_control_deliveries')->insert([
                    'delivery_id' => $headers['delivery-id'], 'tenant_id' => $binding['tenant_id'],
                    'source' => $source, 'command' => $body['command'],
                    'resource_type' => $resource->getMorphClass(), 'resource_id' => $resource->getKey(),
                    'payload_sha256' => hash('sha256', $raw), 'created_at' => now(), 'updated_at' => now(),
                ]);

                return [$resource, $body['command'] === 'privacy_request.open'];
            });
        } catch (QueryException $exception) {
            if (DB::table('governance_control_deliveries')->where('delivery_id', $headers['delivery-id'])->exists()) {
                return response()->json(['outcome' => 'duplicate ignored']);
            }
            throw $exception;
        } catch (ModelNotFoundException) {
            return response()->json(['outcome' => 'resource not found'], 404);
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['outcome' => 'invalid command', 'message' => $exception->getMessage()], 422);
        }

        return response()->json(['outcome' => 'recorded', 'resource_type' => $resource->getMorphClass(), 'resource_id' => $resource->getKey()], $created ? 201 : 200);
    }
}
