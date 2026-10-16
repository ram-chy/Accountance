<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function acceptHeaders(): array
    {
        return [
            'explicit json' => ['application/json'],
            'wildcard' => ['*/*'],
            'browser html' => ['text/html'],
            'no accept header' => [null],
        ];
    }

    /**
     * A protected API endpoint must answer an unauthenticated caller with the
     * 401 JSON envelope no matter what the Accept header says.
     *
     * Regression: a request without an explicit `Accept: application/json` used
     * to trigger the framework's default guest redirect to route('login'). The
     * route does not exist in this headless API, so the lookup threw a
     * RouteNotFoundException *before* the AuthenticationException existed and
     * the caller saw a 500 debug trace instead of a 401.
     */
    #[DataProvider('acceptHeaders')]
    public function test_unauthenticated_api_requests_return_401_json_for_every_accept_header(?string $accept): void
    {
        $request = $accept === null
            ? $this->withoutHeader('Accept')
            : $this->withHeaders(['Accept' => $accept]);

        foreach (['/api/auth/me', '/api/companies', '/api/journals'] as $uri) {
            $this->assertUnauthenticatedJson($request->get($uri));
        }
    }

    /**
     * A browser-like Accept header on an API route must not produce an HTML
     * login redirect or any framework internals.
     */
    public function test_unauthenticated_api_request_with_html_accept_leaks_no_internals(): void
    {
        $response = $this->withHeaders(['Accept' => 'text/html'])->get('/api/auth/me');

        $body = (string) $response->getContent();

        $this->assertUnauthenticatedJson($response);

        foreach (['<html', 'Route [login]', 'RouteNotFoundException', 'vendor', 'D:\\', '/app/'] as $needle) {
            $this->assertStringNotContainsString($needle, $body);
        }
    }

    private function assertUnauthenticatedJson(TestResponse $response): void
    {
        $response
            ->assertStatus(401)
            ->assertHeader('content-type', 'application/json')
            ->assertExactJson([
                'success' => false,
                'message' => 'Unauthenticated.',
            ]);
    }
}
