<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    /**
     * List users.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $perPage = min(max((int) $request->integer('per_page', 15), 1), 100);

        $users = User::query()
            ->with('roles')
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%'.$request->string('search')->trim().'%';
                $query->where(fn ($q) => $q
                    ->where('first_name', 'like', $term)
                    ->orWhere('last_name', 'like', $term)
                    ->orWhere('email', 'like', $term));
            })
            ->when($request->filled('role'), fn ($query) => $query->whereHas(
                'roles',
                fn ($q) => $q->where('name', $request->string('role')->toString())
            ))
            ->when($request->has('is_active'), fn ($query) => $query->where(
                'is_active',
                $request->boolean('is_active')
            ))
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();

        return ApiResponse::success(
            message: 'Users retrieved successfully.',
            data: UserResource::collection($users),
        );
    }

    /**
     * Show a single user.
     */
    public function show(User $user): JsonResponse
    {
        $this->authorize('view', $user);

        return ApiResponse::success(
            message: 'User retrieved successfully.',
            data: new UserResource($user->load('roles.permissions')),
        );
    }

    /**
     * Create a user.
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => $data['email'],
                'mobile_no' => $data['mobile_no'] ?? null,
                'password' => $data['password'],
                'is_active' => true,
            ]);

            $user->syncRoles($data['roles']);
            $user->sendEmailVerificationNotification();

            return $user;
        });

        return ApiResponse::success(
            message: 'User created successfully.',
            data: new UserResource($user->load('roles.permissions')),
            status: 201,
        );
    }

    /**
     * Update a user.
     *
     * Guard rails against privilege escalation:
     *  - a caller may not change their own roles
     *  - a caller may not reactivate themselves
     *  - a caller may not remove their own Admin role
     */
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $data = $request->validated();

        if ((int) $user->id === (int) $request->user()->id) {
            if (array_key_exists('roles', $data)) {
                return ApiResponse::error(
                    message: 'You cannot change your own roles.',
                    errors: ['roles' => ['You cannot change your own roles.']],
                    status: 403,
                );
            }

            if (array_key_exists('is_active', $data) && ! $data['is_active']) {
                return ApiResponse::error(
                    message: 'You cannot deactivate your own account.',
                    errors: ['is_active' => ['You cannot deactivate your own account.']],
                    status: 403,
                );
            }
        }

        $user = DB::transaction(function () use ($user, $data) {
            $attributes = array_filter(
                [
                    'first_name' => $data['first_name'] ?? null,
                    'last_name' => $data['last_name'] ?? null,
                    'email' => $data['email'] ?? null,
                    'mobile_no' => array_key_exists('mobile_no', $data) ? $data['mobile_no'] : null,
                    'password' => $data['password'] ?? null,
                ],
                fn ($value) => ! is_null($value)
            );

            if (array_key_exists('is_active', $data)) {
                $attributes['is_active'] = (bool) $data['is_active'];
            }

            $user->fill($attributes)->save();

            if (array_key_exists('roles', $data)) {
                $user->syncRoles($data['roles']);
            }

            return $user;
        });

        return ApiResponse::success(
            message: 'User updated successfully.',
            data: new UserResource($user->load('roles.permissions')),
        );
    }

    /**
     * Deactivate a user.
     *
     * This is a soft state change, never a destructive delete: accounting
     * records created by the user must remain attributable in later phases.
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->authorize('delete', $user);

        if ((int) $user->id === (int) $request->user()->id) {
            return ApiResponse::error(
                message: 'You cannot deactivate your own account.',
                status: 403,
            );
        }

        $user->forceFill(['is_active' => false])->save();

        return ApiResponse::success(message: 'User deactivated successfully.');
    }
}
