<?php

namespace App\Support;

use App\Models\VendorOperationEvent;
use App\Suite\VendorOperationLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class AuthorizationDenialAudit
{
    public function __construct(private readonly VendorOperationLedger $ledger) {}

    public function capture(Request $request, int $status): void
    {
        if (! config('authorization-audit.enabled') || ! in_array($status, [401, 403], true)) {
            return;
        }
        if ($request->attributes->get('_fynix_authorization_audited') === true) {
            return;
        }
        $requestId = $this->uuidHeader($request->header('X-Request-Id'));
        $deliveryId = (string) Str::uuid();
        $route = $request->route()?->uri() ?? 'unmatched';
        $route = '/'.ltrim(preg_match('#^[A-Za-z0-9_./{}:-]{1,199}$#', $route) ? $route : 'unmatched', '/');
        $record = [
            'delivery_id' => $deliveryId,
            'envelope' => [
                'occurred_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
                'payload' => [
                    'request_id' => $requestId,
                    'operation_id' => $requestId,
                    'operator_subject' => $this->subject($request),
                    'action' => 'authorization.denied',
                    'target' => 'cyberaudit',
                    'outcome' => 'denied',
                    'source_ip' => null,
                    'metadata' => [
                        'http_status' => $status,
                        'http_method' => strtoupper($request->method()),
                        'route_template' => $route,
                    ],
                ],
            ],
        ];
        try {
            $this->ledger->append($record['envelope'], $deliveryId);
        } catch (Throwable) {
            $this->spool($record);
        }
        $request->attributes->set('_fynix_authorization_audited', true);
    }

    public function drain(int $limit = 50): int
    {
        $directory = $this->directory();
        if (! is_dir($directory)) {
            return 0;
        }
        $handled = 0;
        foreach (array_slice($this->files(), 0, $limit) as $file) {
            if (is_link($file) || ! is_file($file)) {
                throw new RuntimeException('Invalid authorization audit spool entry.');
            }
            $record = json_decode((string) file_get_contents($file), true, 16, JSON_THROW_ON_ERROR);
            if (($record['delivery_id'] ?? '').'.json' !== basename($file)) {
                throw new RuntimeException('Authorization audit spool identity mismatch.');
            }
            $deliveryId = (string) $record['delivery_id'];
            if (! VendorOperationEvent::query()->where('delivery_id', $deliveryId)->exists()) {
                try {
                    $this->ledger->append((array) $record['envelope'], $deliveryId);
                } catch (Throwable $exception) {
                    // The append may have committed before a worker was interrupted. Only
                    // acknowledge the spool when the exact immutable delivery now exists.
                    if (! VendorOperationEvent::query()->where('delivery_id', $deliveryId)->exists()) {
                        throw $exception;
                    }
                }
            }
            if (! unlink($file)) {
                throw new RuntimeException('Cannot acknowledge authorization audit spool.');
            }
            $handled++;
        }

        return $handled;
    }

    public function health(): array
    {
        $directory = $this->directory();
        if (! is_dir($directory)) {
            return ['healthy' => true, 'files' => 0, 'stale' => 0, 'invalid' => 0];
        }
        $files = $this->entries();
        $stale = 0;
        $invalid = 0;
        $cutoff = time() - (int) config('authorization-audit.stale_seconds', 300);
        foreach ($files as $file) {
            if (is_link($file) || ! preg_match('/^[0-9a-f-]{36}\.json$/', basename($file))) {
                $invalid++;

                continue;
            }
            if ((int) filemtime($file) < $cutoff) {
                $stale++;
            }
        }

        return ['healthy' => $stale === 0 && $invalid === 0, 'files' => count($files), 'stale' => $stale, 'invalid' => $invalid];
    }

    private function spool(array $record): void
    {
        $directory = $this->directory();
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Cannot create authorization audit spool.');
        }
        if (is_link($directory)) {
            throw new RuntimeException('Authorization audit spool cannot be a symlink.');
        }
        $temporary = $directory.'/.'.$record['delivery_id'].'.tmp';
        $destination = $directory.'/'.$record['delivery_id'].'.json';
        $handle = fopen($temporary, 'x');
        if ($handle === false) {
            throw new RuntimeException('Cannot open authorization audit spool.');
        }
        try {
            $payload = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (! chmod($temporary, 0600)
                || fwrite($handle, $payload) !== strlen($payload)
                || ! fflush($handle)
                || (function_exists('fsync') && ! fsync($handle))) {
                throw new RuntimeException('Cannot persist authorization audit spool.');
            }
        } catch (Throwable $exception) {
            @unlink($temporary);
            throw $exception;
        } finally {
            fclose($handle);
        }
        if (! rename($temporary, $destination)) {
            @unlink($temporary);
            throw new RuntimeException('Cannot commit authorization audit spool.');
        }
    }

    private function files(): array
    {
        $files = array_values(array_filter($this->entries(), fn (string $file): bool => preg_match('/^[0-9a-f-]{36}\.json$/', basename($file)) === 1));
        sort($files);

        return $files;
    }

    private function entries(): array
    {
        $directory = $this->directory();
        if (! is_dir($directory)) {
            return [];
        }

        return array_values(array_map(fn (string $name): string => $directory.'/'.$name, array_filter(scandir($directory) ?: [], fn (string $name): bool => ! in_array($name, ['.', '..'], true))));
    }

    private function directory(): string
    {
        $path = (string) config('authorization-audit.spool');
        if ($path === '' || $path[0] !== '/') {
            throw new RuntimeException('Authorization audit spool must be absolute.');
        }

        return $path;
    }

    private function uuidHeader(?string $value): string
    {
        return is_string($value) && Str::isUuid($value) ? strtolower($value) : (string) Str::uuid();
    }

    private function subject(Request $request): string
    {
        $user = $request->user();
        if ($user?->getAuthIdentifier()) {
            return 'user:'.$user->getAuthIdentifier();
        }
        $credential = (string) $request->bearerToken();
        $key = (string) config('authorization-audit.fingerprint_key');

        return $credential !== '' && strlen($key) >= 32 ? 'machine:'.substr(hash_hmac('sha256', $credential, $key), 0, 32) : 'anonymous';
    }
}
