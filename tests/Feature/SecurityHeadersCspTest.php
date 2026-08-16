<?php

namespace Tests\Feature;

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class SecurityHeadersCspTest extends TestCase
{
    public function test_html_responses_use_a_nonce_and_disallow_dynamic_script_evaluation(): void
    {
        $request = Request::create('/app/login');
        $response = (new SecurityHeaders)->handle(
            $request,
            fn (): Response => new Response('<!doctype html><html></html>', 200, ['Content-Type' => 'text/html'])
        );

        $policy = (string) $response->headers->get('Content-Security-Policy');
        $nonce = Vite::cspNonce();

        $this->assertNotEmpty($nonce);
        $this->assertStringContainsString("script-src 'self' 'nonce-{$nonce}'", $policy);
        $this->assertStringContainsString("style-src 'self' 'nonce-{$nonce}'", $policy);
        $this->assertStringNotContainsString("'unsafe-eval'", $policy);
        $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $policy);
    }
}
