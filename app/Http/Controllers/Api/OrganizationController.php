<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrganizationResource;
use App\Models\Organization;
use App\Services\OrganizationService;
use App\Support\OrganizationPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrganizationController extends Controller
{
    use Concerns;

    public function __construct(private OrganizationService $organizations) {}

    /**
     * Organizations the user belongs to, with their role in each.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return OrganizationResource::collection(
            $request->user()->organizations()->withCount(['members', 'apps'])->orderBy('organization_user.id')->get()
        );
    }

    /**
     * Create an organization; the user becomes its owner and switches to it.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);

        $organization = $this->organizations->create($request->user(), $data['name']);

        return (new OrganizationResource($this->asMember($request, $organization)))->response()->setStatusCode(201);
    }

    /**
     * Rename the current organization.
     */
    public function update(Request $request): OrganizationResource
    {
        $this->authorizeTo(OrganizationPermission::ManageMembers);
        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);

        $organization = $this->organization($request);
        $organization->update($data);

        return new OrganizationResource($this->asMember($request, $organization));
    }

    public function switch(Request $request, Organization $organization): OrganizationResource
    {
        $organization = $request->user()->organizations()->whereKey($organization->id)->firstOrFail();
        $this->organizations->switchTo($request->user(), $organization);

        return new OrganizationResource($organization);
    }

    private function asMember(Request $request, Organization $organization): Organization
    {
        return $request->user()->organizations()->withCount(['members', 'apps'])->findOrFail($organization->id);
    }
}
