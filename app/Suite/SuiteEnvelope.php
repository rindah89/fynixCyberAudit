<?php

namespace App\Suite;

class SuiteEnvelope
{
    public static function sign(
        string $secret,
        int $timestamp,
        string $eventType,
        string $source,
        string $webhookId,
        string $deliveryId,
        string $rawBody,
    ): string {
        $message = implode("\0", ['fynix-v2', (string) $timestamp, $eventType, $source, $webhookId, $deliveryId])."\0".$rawBody;

        return 'v2='.hash_hmac('sha256', $message, $secret);
    }

    public static function verify(array $secrets, array $headers, string $rawBody, int $now, int $tolerance): bool
    {
        $timestamp = (string) ($headers['x-fynix-timestamp'] ?? '');
        $signature = (string) ($headers['x-fynix-signature'] ?? '');
        $eventType = (string) ($headers['x-fynix-event'] ?? '');
        $source = (string) ($headers['x-fynix-source'] ?? '');
        $webhookId = (string) ($headers['x-fynix-webhook-id'] ?? '');
        $deliveryId = (string) ($headers['x-fynix-delivery-id'] ?? '');

        if ($secrets === [] || $signature === '' || $eventType === '' || $source === '' || $webhookId === '' || $deliveryId === '') {
            return false;
        }

        if (! ctype_digit($timestamp) || abs($now - (int) $timestamp) > $tolerance) {
            return false;
        }

        foreach ($secrets as $secret) {
            $expected = self::sign((string) $secret, (int) $timestamp, $eventType, $source, $webhookId, $deliveryId, $rawBody);
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }
}
