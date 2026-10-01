<?php

namespace App\Http\Controllers\Api;

use App\Enums\RoleName;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\RolePermissionSynchroniser;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

class RegisteredUserController extends Controller
{
    /**
     * Register a new user.
     *
     * The new account is created with the Staff role only. Roles are never
     * accepted from the request body, so a self-registering user cannot
     * elevate their own privileges.
     */
    public function store(RegisterRequest $request, RolePermissionSynchroniser $roles): JsonResponse
    {
        $user = DB::transaction(function () use ($request, $roles) {
            $user = User::create([
                'first_name' => $request->validated('first_name'),
                'last_name' => $request->validated('last_name'),
                'email' => $request->validated('email'),
                'mobile_no' => $request->validated('mobile_no'),
                'password' => $request->validated('password'),
                'is_active' => true,
            ]);

            // Roles are never read from the request. A self-registering user
            // always receives Staff and nothing more.
            $roles->ensureBaselineRoles();
            $user->assignRole(RoleName::Staff->value);

            return $user;
        });

        $user->sendEmailVerificationNotification();

        $token = JWTAuth::fromUser($user);

        return ApiResponse::success(
            message: 'Registration successful.',
            data: [
                'token' => $token,
                'token_type' => 'bearer',
                'expires_in' => config('jwt.ttl') * 60,
                'user' => new UserResource($user->load('roles')),
            ],
            status: 201,
        );
    }
}
