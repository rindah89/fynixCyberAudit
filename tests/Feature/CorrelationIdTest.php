<?php

namespace Tests\Feature;

use Tests\TestCase;

class CorrelationIdTest extends TestCase
{
    public function test_response_echoes_a_valid_request_id(): void
    {
        $response = $this->withHeader('X-Request-ID', 'req-1234567890abcdef')
            ->get('/api/suite/ready');

        $response->assertHeader('X-Request-ID', 'req-1234567890abcdef');
    }

    public function test_response_replaces_an_unsafe_request_id(): void
    {
        $response = $this->withHeader('X-Request-ID', "unsafe\nvalue")
            ->get('/api/suite/ready');

        $requestId = $response->headers->get('X-Request-ID');
        $this->assertNotNull($requestId);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9._:-]{16,128}$/', $requestId);
    }
}
