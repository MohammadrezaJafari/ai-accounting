<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Auth\OidcController;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrganizationResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Oidc\OidcClient;
use App\Services\OrganizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private OrganizationService $organizations, private OidcClient $oidc) {}

    /**
     * How the panel may sign in: with a password, with the organization's identity provider, or both.
     */
    public function methods(): JsonResponse
    {
        return response()->json([
            'password' => ! $this->oidc->only(),
            'oidc' => $this->oidc->enabled(),
            'oidc_url' => $this->oidc->enabled() ? url('/auth/oidc/redirect') : null,
            'oidc_logout_url' => $this->oidc->enabled() ? url('/auth/oidc/logout') : null,
            'tenant_required' => $this->oidc->enabled() && $this->oidc->multiTenant(),
        ]);
    }

    /**
     * Trade the one-time code the OIDC callback put in the panel's URL for a dashboard token;
     * the response is the same as password sign-in.
     */
    public function oidcExchange(Request $request): JsonResponse
    {
        abort_unless($this->oidc->enabled(), 404);
        $data = $request->validate(['code' => ['required', 'string', 'max:100']]);

        $login = OidcController::redeem($data['code']);
        $user = $login ? User::query()->find($login['user_id']) : null;

        if (! $user || ! $user->is_active) {
            throw ValidationException::withMessages(['code' => 'ورود منقضی شده است؛ دوباره وارد شوید.']);
        }

        return $this->tokenResponse($user, sid: $login['sid']);
    }

    /**
     * Sign up; the user gets their own organization (named `organization`, e.g. the company, or after them).
     */
    public function register(Request $request): JsonResponse
    {
        $this->ensurePasswordSignIn();

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
        $this->ensurePasswordSignIn();

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

    /**
     * OIDC_ONLY turns password sign-in and sign-up off (only while OIDC_ISSUER is set).
     */
    private function ensurePasswordSignIn(): void
    {
        abort_if($this->oidc->only(), 403, 'ورود با رمز خاموش است؛ با حساب سازمانی وارد شوید.');
    }

    /**
     * @param  string|null  $sid  the identity provider session the token belongs to (for back-channel logout)
     */
    private function tokenResponse(User $user, int $status = 200, ?string $sid = null): JsonResponse
    {
        $token = $user->createToken('dashboard');

        if ($sid !== null) {
            $token->accessToken->forceFill(['oidc_sid' => $sid])->save();
        }

        return response()->json([
            'token' => $token->plainTextToken,
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
