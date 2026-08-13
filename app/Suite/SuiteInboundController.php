<?php

namespace App\Suite;

use App\Models\SuiteInboundDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SuiteInboundController
{
    public function store(Request $request, PpmGateway $gateway): JsonResponse
    {
        $raw = $request->getContent();
        $headers = [];
        foreach (['x-fynix-signature', 'x-fynix-timestamp', 'x-fynix-event', 'x-fynix-source', 'x-fynix-webhook-id', 'x-fynix-delivery-id'] as $name) {
            $headers[$name] = (string) $request->header($name, '');
        }

        $secrets = config('suite.ppm.webhook_secrets', []);
        $tolerance = (int) config('suite.ppm.replay_tolerance', 21600);

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

        $outcome = $gateway->applyPpmEvent($envelope);

        SuiteInboundDelivery::query()->create([
            'delivery_id' => $deliveryId,
            'event_type' => $headers['x-fynix-event'],
            'source' => $headers['x-fynix-source'],
            'outcome' => $outcome,
        ]);

        return response()->json(['outcome' => $outcome]);
    }

    public function health(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'ppm' => (bool) config('suite.ppm.enabled'),
        ]);
    }
}
