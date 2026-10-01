<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    /**
     * Report API availability. No credentials, environment values, server
     * paths or infrastructure details are exposed.
     */
    public function __invoke(): JsonResponse
    {
        $database = 'connected';

        try {
            DB::select('select 1');
        } catch (Throwable) {
            $database = 'unavailable';

            return ApiResponse::error(
                message: 'API is running but a dependency is unavailable.',
                errors: ['database' => ['The database is currently unreachable.']],
                status: 503,
            );
        }

        return ApiResponse::success(
            message: 'API is healthy.',
            data: ['status' => 'ok', 'database' => $database],
        );
    }
}
