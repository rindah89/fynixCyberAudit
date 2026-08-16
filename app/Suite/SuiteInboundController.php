<?php

namespace App\Suite;

use App\Models\SuiteInboundDelivery;
use App\Models\SuiteInboundHighWater;
use App\Models\VendorOperationEvent;
use App\Support\AuthorizationDenialAudit;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SuiteInboundController
{
    public function store(
        Request $request,
        PpmGateway $ppmGateway,
        ItsmGateway $itsmGateway,
        VendorOperationLedger $vendorOperations,
    ): JsonResponse {
        $raw = $request->getContent();
        $headers = [];
        foreach (['x-fynix-signature', 'x-fynix-timestamp', 'x-fynix-event', 'x-fynix-source', 'x-fynix-webhook-id', 'x-fynix-delivery-id'] as $name) {
            $headers[$name] = (string) $request->header($name, '');
        }

        $source = $headers['x-fynix-source'];
        $secrets = match ($source) {
            'itsm' => array_values(array_filter([(string) config('suite.itsm.webhook_secret')])),
            'ppm' => config('suite.ppm.webhook_secrets', []),
            'support' => config('suite.support.webhook_secrets', []),
            default => [],
        };
        $tolerance = (int) match ($source) {
            'itsm' => config('suite.itsm.replay_tolerance', 21600),
            'ppm' => config('suite.ppm.replay_tolerance', 21600),
            'support' => config('suite.support.replay_tolerance', 300),
            default => 0,
        };

        if (! SuiteEnvelope::verify($secrets, $headers, $raw, time(), $tolerance)) {
            return response()->json(['outcome' => 'invalid signature'], 401);
        }

        $envelope = json_decode($raw, true);
        if (! is_array($envelope) || ($envelope['event_type'] ?? null) !== $headers['x-fynix-event']) {
            return response()->json(['outcome' => 'invalid json'], 400);
        }

        $deliveryId = $headers['x-fynix-delivery-id'];
        if (! Str::isUuid($deliveryId)) {
            return response()->json(['outcome' => 'invalid delivery id'], 400);
        }
        if (SuiteInboundDelivery::query()->where('delivery_id', $deliveryId)->exists()
            || VendorOperationEvent::query()->where('delivery_id', $deliveryId)->exists()) {
            return response()->json(['outcome' => 'duplicate ignored']);
        }

        if ($source === 'itsm' && (! config('suite.itsm.enabled') || $headers['x-fynix-webhook-id'] !== (string) config('suite.itsm.webhook_id') || (string) ($envelope['tenant_id'] ?? '') !== (string) config('suite.itsm.remote_tenant_id'))) {
            return response()->json(['outcome' => 'binding disabled'], 503);
        }

        if ($source === 'support' && (
            ! config('suite.support.enabled')
            || $headers['x-fynix-webhook-id'] !== (string) config('suite.support.webhook_id')
            || (string) ($envelope['tenant_id'] ?? '') !== (string) config('suite.support.remote_tenant_id')
            || $headers['x-fynix-event'] !== 'support.operator.event'
            || (string) ($envelope['entity_type'] ?? '') !== 'vendor_operation'
            || (string) ($envelope['entity_id'] ?? '') !== (string) data_get($envelope, 'payload.request_id')
        )) {
            return response()->json(['outcome' => 'binding disabled'], 503);
        }

        if ($source === 'support') {
            $occurredAt = CarbonImmutable::parse((string) ($envelope['occurred_at'] ?? ''))->utc();
            if ($occurredAt->greaterThan(now()->addSeconds($tolerance))) {
                return response()->json(['outcome' => 'invalid future event timestamp'], 400);
            }
            try {
                $vendorOperations->append($envelope, $deliveryId);
            } catch (UniqueConstraintViolationException $exception) {
                if (VendorOperationEvent::query()->where('delivery_id', $deliveryId)->exists()) {
                    return response()->json(['outcome' => 'duplicate ignored']);
                }

                throw $exception;
            }
            $outcome = 'recorded';
        } elseif ($source === 'itsm') {
            $occurredAt = CarbonImmutable::parse((string) ($envelope['occurred_at'] ?? ''))->utc();
            $identity = [
                'local_tenant_id' => (string) config('suite.itsm.local_tenant_id'),
                'source' => 'itsm',
                'entity_type' => (string) ($envelope['entity_type'] ?? ''),
                'entity_id' => (string) ($envelope['entity_id'] ?? ''),
            ];
            $watermark = SuiteInboundHighWater::query()->where($identity)->first();
            if ($watermark && $occurredAt->lessThanOrEqualTo($watermark->occurred_at)) {
                $outcome = 'stale';
            } else {
                $outcome = $itsmGateway->applyEvent($envelope);
                SuiteInboundHighWater::query()->updateOrCreate($identity, ['occurred_at' => $occurredAt]);
            }
        } else {
            $outcome = $ppmGateway->applyPpmEvent($envelope);
        }

        SuiteInboundDelivery::query()->create([
            'delivery_id' => $deliveryId,
            'event_type' => $headers['x-fynix-event'],
            'source' => $headers['x-fynix-source'],
            'outcome' => $outcome,
        ]);

        return response()->json(['outcome' => $outcome]);
    }

    public function ready(ItsmGateway $gateway, VendorOperationLedger $vendorOperations, AuthorizationDenialAudit $authorizationAudit): JsonResponse
    {
        $missing = config('suite.itsm.enabled') ? $gateway->missingConfiguration() : [];
        $supportMissing = config('suite.support.enabled') ? array_keys(array_filter([
            'webhook_id' => ! config('suite.support.webhook_id'),
            'webhook_secrets' => config('suite.support.webhook_secrets', []) === [],
            'remote_tenant_id' => ! config('suite.support.remote_tenant_id'),
        ])) : [];
        $integrity = $vendorOperations->integrityStatus();
        $integrityOk = $integrity['status'] === 'ok' && $integrity['fresh'];
        $anchor = $vendorOperations->anchorStatus();
        $anchorOk = ! config('suite.support.anchor.enabled') || $anchor['fresh'];
        $authorizationHealth = $authorizationAudit->health();
        $authorizationOk = ! config('authorization-audit.enabled') || $authorizationHealth['healthy'];
        $ready = $missing === [] && $supportMissing === [] && $integrityOk && $anchorOk && $authorizationOk;

        return response()->json([
            'status' => $ready ? 'ok' : 'not_ready',
            'ppm' => (bool) config('suite.ppm.enabled'),
            'itsm' => (bool) config('suite.itsm.enabled'),
            'vendor_operations' => [
                'enabled' => (bool) config('suite.support.enabled'),
                'integrity' => $integrityOk ? 'ok' : ($integrity['fresh'] ? $integrity['status'] : 'stale'),
                'integrity_checked_at' => $integrity['checked_at'],
                'missing_configuration' => $supportMissing,
                'last_recorded_at' => VendorOperationEvent::query()->latest('id')->value('occurred_at'),
                'anchor' => [
                    'enabled' => (bool) config('suite.support.anchor.enabled'),
                    'fresh' => $anchor['fresh'],
                    'last_anchor_at' => $anchor['last_anchor_at'],
                    'last_anchor_key' => $anchor['last_anchor_key'],
                ],
            ],
            'authorization_audit' => [
                'enabled' => (bool) config('authorization-audit.enabled'),
                ...$authorizationHealth,
            ],
            'last_inbound_outcome' => SuiteInboundDelivery::query()->where('source', 'itsm')->latest('id')->value('outcome'),
        ], $ready ? 200 : 503);
    }
}
