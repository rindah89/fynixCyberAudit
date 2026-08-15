<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class RequestCorrelation
{
    public function handle(Request $request, Closure $next): Response
    {
        $candidate = (string) $request->headers->get('X-Request-ID', '');
        $requestId = preg_match('/^[A-Za-z0-9._:-]{16,128}$/', $candidate) === 1
            ? $candidate
            : bin2hex(random_bytes(16));

        $request->attributes->set('request_id', $requestId);
        Log::withContext([
            'request_id' => $requestId,
            'operation' => $request->method().' '.$request->route()?->uri(),
        ]);

        $response = $next($request);
        $response->headers->set('X-Request-ID', $requestId);

        return $response;
    }
}
