<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApiErrorHandlingTest extends TestCase
{
    public function test_unknown_api_endpoint_returns_json_404(): void
    {
        $response = $this->getJson('/api/does-not-exist');

        $response
            ->assertNotFound()
            ->assertHeader('content-type', 'application/json')
            ->assertExactJson([
                'success' => false,
                'message' => 'The requested endpoint was not found.',
            ]);
    }

    public function test_unknown_api_endpoint_does_not_return_an_html_page(): void
    {
        $response = $this->get('/api/does-not-exist');

        $response->assertNotFound();
        $this->assertStringNotContainsString('<html', strtolower($response->getContent()));
    }

    public function test_unsupported_method_returns_json_405(): void
    {
        $response = $this->postJson('/api/health');

        $response
            ->assertStatus(405)
            ->assertHeader('content-type', 'application/json')
            ->assertJsonPath('success', false);
    }

    public function test_validation_errors_use_the_response_convention(): void
    {
        Route::middleware('api')->prefix('api')->post('/__test/validation', function () {
            return request()->validate([
                'amount' => ['required', 'numeric'],
            ]);
        });

        $response = $this->postJson('/api/__test/validation', []);

        $response
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['success', 'message', 'errors'])
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_unexpected_errors_do_not_leak_details_when_debug_is_disabled(): void
    {
        Route::middleware('api')->prefix('api')->get('/__test/boom', function () {
            throw new \RuntimeException('secret internal detail');
        });

        config(['app.debug' => false]);

        $response = $this->getJson('/api/__test/boom');

        $response
            ->assertStatus(500)
            ->assertExactJson([
                'success' => false,
                'message' => 'An unexpected error occurred.',
            ]);
    }
}
