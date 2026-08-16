<?php

namespace App\Http\Middleware;

use App\Support\AuthorizationDenialAudit;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class CaptureAuthorizationDenials
{
    public function __construct(private readonly AuthorizationDenialAudit $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (in_array($response->getStatusCode(), [401, 403], true)) {
            try {
                $this->audit->capture($request, $response->getStatusCode());
            } catch (Throwable) {
                report(new \RuntimeException('authorization_audit phase=persist outcome=failed'));
            }
        }

        return $response;
    }
}
