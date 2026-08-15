<?php

namespace App\Support\Observability;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class SentryTransport
{
    public function __construct(private ?ClientInterface $client = null)
    {
        $this->client ??= new Client();
    }

    public function capture(Throwable $error): void
    {
        if ($this->shouldIgnore($error)) {
            return;
        }

        $target = $this->parseDsn((string) config('observability.dsn'));
        if ($target === null) {
            return;
        }

        try {
            $response = $this->client->request('POST', $target['endpoint'], [
                'connect_timeout' => 1,
                'timeout' => max(0.5, (float) config('observability.timeout_seconds', 2)),
                'http_errors' => false,
                'headers' => [
                    'X-Sentry-Auth' => sprintf(
                        'Sentry sentry_version=7, sentry_client=fynix-cyberaudit/1.0, sentry_key=%s',
                        $target['key']
                    ),
                ],
                'json' => $this->event($error),
            ]);
            if ($response->getStatusCode() >= 300) {
                error_log('[observability] central error delivery returned HTTP '.$response->getStatusCode());
            }
        } catch (Throwable $transportError) {
            error_log('[observability] central error delivery failed: '.$transportError->getMessage());
        }
    }

    /** @return array{endpoint: string, key: string}|null */
    private function parseDsn(string $dsn): ?array
    {
        if ($dsn === '') {
            return null;
        }

        $parts = parse_url($dsn);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'], $parts['user'], $parts['path'])) {
            error_log('[observability] invalid SENTRY_DSN; central reporting disabled');

            return null;
        }
        $project = basename(trim($parts['path'], '/'));
        if ($project === '') {
            error_log('[observability] invalid SENTRY_DSN project; central reporting disabled');

            return null;
        }

        $prefix = trim((string) dirname((string) $parts['path']), '/.');
        $base = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        $endpoint = $base.($prefix === '' ? '' : '/'.$prefix).'/api/'.$project.'/store/';

        return ['endpoint' => $endpoint, 'key' => $parts['user']];
    }

    /** @return array<string, mixed> */
    private function event(Throwable $error): array
    {
        $frames = array_map(static function (array $frame): array {
            return array_filter([
                'filename' => isset($frame['file']) ? basename((string) $frame['file']) : null,
                'abs_path' => $frame['file'] ?? null,
                'lineno' => $frame['line'] ?? null,
                'module' => $frame['class'] ?? null,
                'function' => $frame['function'] ?? null,
                'in_app' => true,
            ], static fn (mixed $value): bool => $value !== null);
        }, array_reverse($error->getTrace()));
        $frames[] = [
            'filename' => basename($error->getFile()),
            'abs_path' => $error->getFile(),
            'lineno' => $error->getLine(),
            'in_app' => true,
        ];

        return array_filter([
            'event_id' => bin2hex(random_bytes(16)),
            'timestamp' => gmdate('Y-m-d\TH:i:s.v\Z'),
            'platform' => 'php',
            'level' => 'error',
            'environment' => config('observability.environment'),
            'release' => config('observability.release'),
            'tags' => [
                'service.namespace' => 'fynix-suite',
                'service.name' => 'cyberaudit',
                'service.runtime' => app()->runningInConsole() ? 'worker' : 'app',
            ],
            'exception' => ['values' => [[
                'type' => $error::class,
                'value' => $this->sanitizeMessage($error->getMessage()),
                'stacktrace' => ['frames' => $frames],
            ]]],
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function sanitizeMessage(string $message): string
    {
        $message = preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i', 'Bearer [Filtered]', $message) ?? $message;
        $message = preg_replace('/([?&](?:token|key|secret|password)=)[^&\s]+/i', '$1[Filtered]', $message) ?? $message;

        return mb_substr($message, 0, 2048);
    }

    private function shouldIgnore(Throwable $error): bool
    {
        if ($error instanceof ValidationException || $error instanceof HttpResponseException) {
            return true;
        }

        return $error instanceof HttpExceptionInterface && $error->getStatusCode() < 500;
    }
}
