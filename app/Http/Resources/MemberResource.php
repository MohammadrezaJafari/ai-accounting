<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A user as a member of an organization (loaded through `Organization::members()`).
 */
class MemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->pivot->role->value,
            'role_label' => $this->pivot->role->label(),
            'joined_at' => $this->pivot->created_at,
        ];
    }
}
