<?php

namespace App\Http\Controllers\Service;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateServiceKey;
use App\Services\ProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * The Rahap Hub's provisioning endpoints (docs/architecture/service-contract-fa.md in company-os).
 */
class ProvisioningController extends Controller
{
    public function __construct(private ProvisioningService $provisioning) {}

    /**
     * PUT /api/service/v1/organizations/{key}: the tenant's organization (holding or root company).
     */
    public function organization(Request $request, string $key): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'tenant' => ['required', 'string', 'max:100'],
            'kind' => ['required', Rule::in(['holding', 'company', 'workspace'])],
            'name' => ['required', 'string', 'max:255'],
            'parent' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails() || strlen($key) > 255) {
            return AuthenticateServiceKey::error($request, 'validation', 'The given data was invalid.', 422, $validator->errors()->toArray() ?: ['key' => ['The key is too long.']]);
        }

        $data = $validator->validated();

        if (! $this->provisioning->keeps($data['kind'], $data['parent'] ?? null)) {
            return response()->json(['ignored' => true], 202);
        }

        $organization = $this->provisioning->upsertOrganization($data['tenant'], $key, $data['name']);

        if (! $organization) {
            return AuthenticateServiceKey::error($request, 'conflict', 'This tenant already has its organization here (one per tenant).', 409);
        }

        return response()->json(['ok' => true, 'id' => (string) $organization->id, 'slug' => ProvisioningService::slug($key)]);
    }

    /**
     * PUT /api/service/v1/members: the whole membership of an organization.
     */
    public function members(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'tenant' => ['required', 'string', 'max:100'],
            'organization' => ['required', 'string', 'max:255'],
            'members' => ['present', 'array'],
            'members.*.sub' => ['nullable', 'string', 'max:255'],
            'members.*.email' => ['required', 'email', 'max:255'],
            'members.*.name' => ['nullable', 'string', 'max:255'],
            'members.*.role' => ['required', Rule::in(array_keys(ProvisioningService::ROLES))],
            'members.*.unit' => ['nullable', 'string', 'max:255'],
            'members.*.kind' => ['nullable', 'string', 'max:50'],
        ]);

        if ($validator->fails()) {
            return AuthenticateServiceKey::error($request, 'validation', 'The given data was invalid.', 422, $validator->errors()->toArray());
        }

        $data = $validator->validated();
        $organization = $this->provisioning->find($data['tenant'], $data['organization']);

        if (! $organization) {
            return $this->provisioning->ignoresMembersOf($data['tenant'], $data['organization'])
                ? response()->json(['ignored' => true], 202)
                : AuthenticateServiceKey::error($request, 'not_found', 'Unknown organization.', 404);
        }

        return response()->json(['ok' => true] + $this->provisioning->syncMembers($organization, $data['members']));
    }
}
