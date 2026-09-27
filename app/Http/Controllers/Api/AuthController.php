<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrganizationResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\OrganizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private OrganizationService $organizations) {}

    /**
     * Sign up; the user gets their own organization (named `organization`, e.g. the company, or after them).
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'organization' => ['nullable', 'string', 'max:100'],
        ]);

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => User::ROLE_CUSTOMER,
        ]);
        $this->organizations->create($user, $data['organization'] ?? $data['name']);

        return $this->tokenResponse($user, 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages(['email' => 'This account is disabled.']);
        }

        return $this->tokenResponse($user);
    }

    /**
     * The user, their current organization (with role and permissions) and all their organizations.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => new UserResource($request->user())] + $this->organizationsOf($request->user()));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['ok' => true]);
    }

    private function tokenResponse(User $user, int $status = 200): JsonResponse
    {
        return response()->json([
            'token' => $user->createToken('dashboard')->plainTextToken,
            'user' => new UserResource($user),
        ] + $this->organizationsOf($user), $status);
    }

    /**
     * @return array{organization: OrganizationResource, organizations: mixed}
     */
    private function organizationsOf(User $user): array
    {
        $current = $this->organizations->current($user);
        $organizations = $user->organizations()->orderBy('organization_user.id')->get();

        return [
            'organization' => new OrganizationResource($organizations->firstWhere('id', $current->id)),
            'organizations' => OrganizationResource::collection($organizations),
        ];
    }
}
