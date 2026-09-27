<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InvitationResource;
use App\Http\Resources\OrganizationResource;
use App\Models\OrganizationInvitation;
use App\Services\OrganizationService;
use App\Support\OrganizationPermission;
use App\Support\OrganizationRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class InvitationController extends Controller
{
    use Concerns;

    public function __construct(private OrganizationService $organizations) {}

    /**
     * Pending invitations of the current organization.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizeTo(OrganizationPermission::ManageMembers);

        $invitations = InvitationResource::collection(
            $this->organization($request)->invitations()->with('inviter')->latest('id')->get()
        );
        $invitations->collection->each->withUrl();

        return $invitations;
    }

    /**
     * Invite someone by email. The response carries the invitation link to share if email is not set up.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorizeTo(OrganizationPermission::ManageMembers);
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::enum(OrganizationRole::class)],
        ]);

        $invitation = $this->organizations->invite($this->organization($request), $request->user(), $data['email'], OrganizationRole::from($data['role']));

        return (new InvitationResource($invitation->load('inviter')))->withUrl()->response()->setStatusCode(201);
    }

    public function destroy(Request $request, OrganizationInvitation $invitation): JsonResponse
    {
        $this->authorizeTo(OrganizationPermission::ManageMembers);
        abort_unless($invitation->organization_id === $this->organization($request)->id, 404);
        $invitation->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * The invitation behind a link, for the acceptance page.
     */
    public function show(string $token): InvitationResource
    {
        return new InvitationResource($this->findByToken($token)->load(['organization', 'inviter']));
    }

    public function accept(Request $request, string $token): OrganizationResource
    {
        $organization = $this->organizations->accept($this->findByToken($token), $request->user());

        return new OrganizationResource($request->user()->organizations()->withCount(['members', 'apps'])->findOrFail($organization->id));
    }

    private function findByToken(string $token): OrganizationInvitation
    {
        return OrganizationInvitation::query()->where('token', $token)->firstOr(
            fn () => abort(404, 'این دعوت وجود ندارد یا قبلاً استفاده شده است.')
        );
    }
}
