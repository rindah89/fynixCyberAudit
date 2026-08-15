<?php

namespace App\Suite;

use App\Models\VendorOperationEvent;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class VendorOperationLedger
{
    /** @param array<string, mixed> $envelope */
    public function append(array $envelope, string $deliveryId): VendorOperationEvent
    {
        Validator::make($envelope, [
            'occurred_at' => ['required', 'date'],
            'payload' => ['required', 'array'],
        ])->validate();
        $payload = Validator::make((array) ($envelope['payload'] ?? []), [
            'request_id' => ['required', 'uuid'],
            'operation_id' => ['required', 'uuid'],
            'operator_subject' => ['required', 'string', 'max:190'],
            'action' => ['required', 'string', 'max:120', 'regex:/^[a-z][a-z0-9_.-]+$/'],
            'target' => ['required', 'string', 'max:190'],
            'outcome' => ['required', 'in:requested,approved,denied,started,succeeded,failed,cancelled'],
            'source_ip' => ['nullable', 'ip'],
            'itsm_record' => ['nullable', 'regex:/^(CHG|INC)[0-9]+$/', 'max:64'],
            'before_sha256' => ['nullable', 'regex:/^[a-f0-9]{64}$/'],
            'after_sha256' => ['nullable', 'regex:/^[a-f0-9]{64}$/'],
            'metadata' => ['nullable', 'array', 'max:20'],
        ])->validate();

        $metadata = $payload['metadata'] ?? null;
        if ($metadata !== null) {
            $encoded = json_encode($metadata, JSON_THROW_ON_ERROR);
            if (strlen($encoded) > 16384 || $this->arrayDepth($metadata) > 5) {
                throw ValidationException::withMessages([
                    'metadata' => 'Metadata must be at most 16 KiB and five levels deep.',
                ]);
            }
        }

        $record = [
            'request_id' => $payload['request_id'],
            'operation_id' => $payload['operation_id'],
            'delivery_id' => $deliveryId,
            'operator_subject' => $payload['operator_subject'],
            'action' => $payload['action'],
            'target' => $payload['target'],
            'outcome' => $payload['outcome'],
            'source_ip' => $payload['source_ip'] ?? null,
            'itsm_record' => $payload['itsm_record'] ?? null,
            'before_sha256' => $payload['before_sha256'] ?? null,
            'after_sha256' => $payload['after_sha256'] ?? null,
            'metadata' => $metadata,
            'occurred_at' => CarbonImmutable::parse((string) $envelope['occurred_at'])->utc(),
        ];

        return DB::transaction(function () use ($record): VendorOperationEvent {
            $head = DB::table('vendor_operation_ledger_heads')
                ->where('id', 1)
                ->lockForUpdate()
                ->first();
            $previousHash = (string) $head->last_hash;
            $canonical = $this->canonical($record);
            $eventHash = $this->sign($previousHash."\0".$canonical);

            $event = VendorOperationEvent::query()->create([
                ...$record,
                'previous_hash' => $previousHash,
                'event_hash' => $eventHash,
            ]);
            DB::table('vendor_operation_ledger_heads')->where('id', 1)->update([
                'last_hash' => $eventHash,
                'event_count' => ((int) $head->event_count) + 1,
                'integrity_status' => 'ok',
                'integrity_checked_at' => now(),
                'updated_at' => now(),
            ]);

            return $event;
        }, 3);
    }

    public function verifyChain(): bool
    {
        $previousHash = str_repeat('0', 64);
        foreach (VendorOperationEvent::query()->orderBy('id')->cursor() as $event) {
            $record = $event->only([
                'request_id',
                'operation_id',
                'delivery_id',
                'operator_subject',
                'action',
                'target',
                'outcome',
                'source_ip',
                'itsm_record',
                'before_sha256',
                'after_sha256',
                'metadata',
                'occurred_at',
            ]);
            $expected = $this->sign($previousHash."\0".$this->canonical($record));
            if (! hash_equals($previousHash, $event->previous_hash)
                || ! hash_equals($expected, $event->event_hash)) {
                return $this->recordIntegrity(false);
            }
            $previousHash = $event->event_hash;
        }

        $head = DB::table('vendor_operation_ledger_heads')->where('id', 1)->first();

        $valid = $head !== null
            && hash_equals($previousHash, (string) $head->last_hash)
            && VendorOperationEvent::query()->count() === (int) $head->event_count;

        return $this->recordIntegrity($valid);
    }

    /** @return array{status: string, checked_at: ?string, fresh: bool} */
    public function integrityStatus(): array
    {
        $head = DB::table('vendor_operation_ledger_heads')->where('id', 1)->first();
        $checkedAt = $head?->integrity_checked_at;
        $fresh = $checkedAt !== null
            && CarbonImmutable::parse((string) $checkedAt)->greaterThanOrEqualTo(
                now()->subSeconds((int) config('suite.support.integrity_max_age', 86400))
            );

        return ['status' => (string) ($head?->integrity_status ?? 'unknown'), 'checked_at' => $checkedAt, 'fresh' => $fresh];
    }

    private function recordIntegrity(bool $valid): bool
    {
        DB::table('vendor_operation_ledger_heads')->where('id', 1)->update([
            'integrity_status' => $valid ? 'ok' : 'failed',
            'integrity_checked_at' => now(),
            'updated_at' => now(),
        ]);

        return $valid;
    }

    private function sign(string $message): string
    {
        $key = (string) config('suite.support.ledger_key');
        if (strlen($key) < 32) {
            throw new RuntimeException('Vendor operation ledger key is not configured securely.');
        }

        return hash_hmac('sha256', $message, $key);
    }

    private function arrayDepth(mixed $value): int
    {
        if (! is_array($value) || $value === []) {
            return 0;
        }

        return 1 + max(array_map(fn ($item) => $this->arrayDepth($item), $value));
    }

    /** @param array<string, mixed> $record */
    private function canonical(array $record): string
    {
        $normalize = function (mixed $value) use (&$normalize): mixed {
            if ($value instanceof DateTimeInterface) {
                return CarbonImmutable::instance($value)->utc()->format('Y-m-d\TH:i:s\Z');
            }
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map($normalize, $value);
        };

        ksort($record);

        return json_encode(
            $normalize($record),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }
}
