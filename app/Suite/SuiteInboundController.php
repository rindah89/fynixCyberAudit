<?php

namespace App\Suite;

use App\Models\SuiteInboundDelivery;
use App\Models\SuiteInboundHighWater;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SuiteInboundController
{
    public function store(Request $request, PpmGateway $ppmGateway, ItsmGateway $itsmGateway): JsonResponse
    {
        $raw = $request->getContent();
        $headers = [];
        foreach (['x-fynix-signature', 'x-fynix-timestamp', 'x-fynix-event', 'x-fynix-source', 'x-fynix-webhook-id', 'x-fynix-delivery-id'] as $name) {
            $headers[$name] = (string) $request->header($name, '');
        }

        $source = $headers['x-fynix-source'];
        $secrets = $source === 'itsm'
            ? array_values(array_filter([(string) config('suite.itsm.webhook_secret')]))
            : config('suite.ppm.webhook_secrets', []);
        $tolerance = (int) config($source === 'itsm' ? 'suite.itsm.replay_tolerance' : 'suite.ppm.replay_tolerance', 21600);

        if (! SuiteEnvelope::verify($secrets, $headers, $raw, time(), $tolerance)) {
            return response()->json(['outcome' => 'invalid signature'], 401);
        }

        $envelope = json_decode($raw, true);
        if (! is_array($envelope) || ($envelope['event_type'] ?? null) !== $headers['x-fynix-event']) {
            return response()->json(['outcome' => 'invalid json'], 400);
        }

        $deliveryId = $headers['x-fynix-delivery-id'];
        if (SuiteInboundDelivery::query()->where('delivery_id', $deliveryId)->exists()) {
            return response()->json(['outcome' => 'duplicate ignored']);
        }

        if ($source === 'itsm' && (! config('suite.itsm.enabled') || $headers['x-fynix-webhook-id'] !== (string) config('suite.itsm.webhook_id') || (string) ($envelope['tenant_id'] ?? '') !== (string) config('suite.itsm.remote_tenant_id'))) {
            return response()->json(['outcome' => 'binding disabled'], 503);
        }

        if ($source === 'itsm') {
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

    public function ready(ItsmGateway $gateway): JsonResponse
    {
        $missing = config('suite.itsm.enabled') ? $gateway->missingConfiguration() : [];
        return response()->json([
            'status' => $missing === [] ? 'ok' : 'not_ready',
            'release_sha' => env('FYNIX_RELEASE_SHA', 'development'),
            'ppm' => (bool) config('suite.ppm.enabled'),
            'itsm' => (bool) config('suite.itsm.enabled'),
            'last_inbound_outcome' => SuiteInboundDelivery::query()->where('source', 'itsm')->latest('id')->value('outcome'),
        ], $missing === [] ? 200 : 503);
    }
}
