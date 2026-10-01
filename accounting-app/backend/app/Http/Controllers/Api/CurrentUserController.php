<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CurrentUserController extends Controller
{
    /**
     * Return the authenticated user's safe profile.
     */
    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success(
            message: 'Current user retrieved successfully.',
            data: new UserResource($request->user()->load('roles.permissions')),
        );
    }
}
