<?php

namespace App\Http\Controllers\Api\v1;

use App\Enums\Role;
use App\Http\Requests\DeleteUserRequest;
use App\Http\Requests\ListUsersRequest;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Requests\UpdateUserRoleRequest;
use App\Http\Resources\UserCollection;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class UserController extends BaseController
{
    public function __construct()
    {
        $this->middleware(['auth.api', 'throttle:api']);
    }

    /**
     * Returns a collection of users.
     *
     * @param ListUsersRequest $request
     *
     * @return JsonResponse
     */
    public function index(ListUsersRequest $request): JsonResponse
    {
        $users = User::query()->orderBy('name')->get();

        return $this->successResponse(
            new UserCollection($users),
        );
    }

    /**
     * Store a newly created user in storage.
     *
     * @param StoreUserRequest $request
     *
     * @return JsonResponse
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = User::create($request->validated());

        return $this->successResponse(
            new UserResource($user),
            Response::HTTP_CREATED
        );
    }

    /**
     * Update the specified user in storage.
     *
     * @param UpdateUserRequest $request
     * @param User $user
     *
     * @return JsonResponse
     */
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $user->fill($request->validated());
        $user->save();

        return $this->successResponse(
            new UserResource($user),
        );
    }

    /**
     * Remove the specified user from storage.
     *
     * @param DeleteUserRequest $request
     * @param User $user
     *
     * @return Response
     */
    public function destroy(DeleteUserRequest $request, User $user): Response
    {
        $user->delete();

        return response()->noContent();
    }

    /**
     * Assign a role to the specified user.
     *
     * @param UpdateUserRoleRequest $request
     * @param User $user
     *
     * @return JsonResponse
     */
    public function assignRole(UpdateUserRoleRequest $request, User $user): JsonResponse
    {
        $user->role = Role::from($request->validated()['role']);
        $user->save();

        return $this->successResponse(
            new UserResource($user),
        );
    }
}
