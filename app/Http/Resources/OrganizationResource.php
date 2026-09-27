<?php

namespace App\Http\Resources;

use App\Support\OrganizationRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An organization as seen by one of its members (`pivot.role` is that member's role).
 */
class OrganizationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $role = $this->pivot?->role;
        $role = $role instanceof OrganizationRole ? $role : ($role ? OrganizationRole::from($role) : null);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'role' => $role?->value,
            'role_label' => $role?->label(),
            'permissions' => $role?->permissions() ?? [],
            'members_count' => $this->whenCounted('members'),
            'apps_count' => $this->whenCounted('apps'),
            'created_at' => $this->created_at,
        ];
    }
}
