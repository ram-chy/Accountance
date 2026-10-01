<?php

namespace Tests\Unit;

use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Tests\TestCase;

class ApiResponseTest extends TestCase
{
    public function test_success_response_follows_the_convention(): void
    {
        $response = ApiResponse::success('Operation completed successfully.', ['id' => 1]);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => ['id' => 1],
        ], $response->getData(true));
    }

    public function test_success_response_omits_data_when_not_provided(): void
    {
        $response = ApiResponse::success('Done.');

        $this->assertSame([
            'success' => true,
            'message' => 'Done.',
        ], $response->getData(true));
    }

    public function test_error_response_follows_the_convention(): void
    {
        $response = ApiResponse::error('Something went wrong.', ['name' => ['Required.']], 422);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame([
            'success' => false,
            'message' => 'Something went wrong.',
            'errors' => ['name' => ['Required.']],
        ], $response->getData(true));
    }

    public function test_error_response_omits_errors_when_not_provided(): void
    {
        $response = ApiResponse::error('Not found.', status: 404);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame([
            'success' => false,
            'message' => 'Not found.',
        ], $response->getData(true));
    }
}
