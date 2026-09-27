<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MemberResource;
use App\Models\User;
use App\Services\OrganizationService;
use App\Support\OrganizationPermission;
use App\Support\OrganizationRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class MemberController extends Controller
{
    use Concerns;

    public function __construct(private OrganizationService $organizations) {}

    /**
     * Members of the current organization; every member can see the team.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return MemberResource::collection($this->organization($request)->members()->orderBy('organization_user.id')->get());
    }

    public function update(Request $request, User $member): MemberResource
    {
        $this->authorizeTo(OrganizationPermission::ManageMembers);
        $data = $request->validate(['role' => ['required', Rule::enum(OrganizationRole::class)]]);

        $organization = $this->organization($request);
        $member = $organization->members()->findOrFail($member->id);
        $this->organizations->changeRole($organization, $member, OrganizationRole::from($data['role']));

        return new MemberResource($organization->members()->findOrFail($member->id));
    }

    /**
     * Remove a member, or leave the organization when `$member` is the user themself.
     */
    public function destroy(Request $request, User $member): JsonResponse
    {
        if ($member->id !== $request->user()->id) {
            $this->authorizeTo(OrganizationPermission::ManageMembers);
        }

        $organization = $this->organization($request);
        $member = $organization->members()->findOrFail($member->id);
        $this->organizations->removeMember($organization, $member);

        return response()->json(['ok' => true]);
    }
}
