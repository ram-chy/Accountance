<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

class ApiResponse
{
    /**
     * Build a successful API response using the project-wide convention.
     */
    public static function success(
        string $message = 'Operation completed successfully.',
        mixed $data = null,
        int $status = 200,
        array $headers = [],
    ): JsonResponse {
        $payload = [
            'success' => true,
            'message' => $message,
        ];

        if (! is_null($data)) {
            $payload['data'] = $data;
        }

        return response()->json($payload, $status, $headers);
    }

    /**
     * Build an error API response using the project-wide convention.
     */
    public static function error(
        string $message = 'Something went wrong.',
        mixed $errors = null,
        int $status = 400,
        array $headers = [],
    ): JsonResponse {
        $payload = [
            'success' => false,
            'message' => $message,
        ];

        if (! is_null($errors)) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $status, $headers);
    }
}
