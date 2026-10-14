<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Requests\Api\V1\RegisterRequest;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = User::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
        ]);

        return ApiResponse::success([
            'token' => $user->createToken('orderforge-client')->plainTextToken,
            'user' => $this->userPayload($user),
        ], $request->attributes->get('request_id'), 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = User::query()->where('email', $validated['email'])->first();

        if ($user === null || ! Hash::check($validated['password'], $user->password)) {
            return ApiResponse::error(
                'UNAUTHORIZED',
                'Email or password is invalid.',
                [],
                401,
                $request->attributes->get('request_id'),
            );
        }

        return ApiResponse::success([
            'token' => $user->createToken('orderforge-client')->plainTextToken,
            'user' => $this->userPayload($user),
        ], $request->attributes->get('request_id'));
    }

    public function logout(Request $request): JsonResponse
    {
        $accessToken = $request->user()?->currentAccessToken();

        if ($accessToken instanceof PersonalAccessToken) {
            $accessToken->delete();
        }

        return ApiResponse::success(['message' => 'Logged out.'], $request->attributes->get('request_id'));
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        return ApiResponse::success(
            $this->userPayload($user),
            $request->attributes->get('request_id'),
        );
    }

    /** @return array{id: int, name: string, email: string, role: string} */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
        ];
    }
}
