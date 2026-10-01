<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_returns_http_200(): void
    {
        $this->getJson('/api/health')->assertOk();
    }

    public function test_health_endpoint_follows_the_response_convention(): void
    {
        $response = $this->getJson('/api/health');

        $response
            ->assertOk()
            ->assertJsonStructure(['success', 'message', 'data'])
            ->assertJson([
                'success' => true,
                'message' => 'API is healthy.',
                'data' => ['status' => 'ok', 'database' => 'connected'],
            ]);
    }

    public function test_health_endpoint_does_not_expose_infrastructure_details(): void
    {
        $body = $this->getJson('/api/health')->getContent();

        foreach (['DB_', 'password', '127.0.0.1', 'root', 'vendor\\', 'laravel', 'mysql', '8.4'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $body);
        }
    }
}
