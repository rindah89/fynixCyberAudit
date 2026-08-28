<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforcePrivacyRestriction
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $processingStopped = $user?->privacy_restricted_at !== null
            || $user?->processing_objection_at !== null;

        if ($processingStopped && ! $request->is('api/governance/privacy/*')) {
            return response()->json([
                'message' => 'Processing is restricted for this account.',
                'code' => 'privacy_processing_restricted',
            ], 423);
        }

        return $next($request);
    }
}
