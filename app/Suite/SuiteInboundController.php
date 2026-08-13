<?php

namespace App\Suite;

use App\Models\SuiteInboundDelivery;
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

        if ($source === 'itsm' && (! config('suite.itsm.enabled') || $headers['x-fynix-webhook-id'] !== (string) config('suite.itsm.webhook_id'))) {
            return response()->json(['outcome' => 'binding disabled'], 503);
        }

        $outcome = $source === 'itsm' ? $itsmGateway->applyEvent($envelope) : $ppmGateway->applyPpmEvent($envelope);

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
            'ppm' => (bool) config('suite.ppm.enabled'),
            'itsm' => (bool) config('suite.itsm.enabled'),
            'last_inbound_outcome' => SuiteInboundDelivery::query()->where('source', 'itsm')->latest('id')->value('outcome'),
        ], $missing === [] ? 200 : 503);
    }
}
