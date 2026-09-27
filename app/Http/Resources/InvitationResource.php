<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvitationResource extends JsonResource
{
    /** Only the organization's managers get the link; the invitee already has it. */
    public bool $withUrl = false;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'role' => $this->role->value,
            'role_label' => $this->role->label(),
            'organization' => $this->whenLoaded('organization', fn () => ['id' => $this->organization->id, 'name' => $this->organization->name]),
            'invited_by' => $this->whenLoaded('inviter', fn () => $this->inviter?->name),
            'url' => $this->when($this->withUrl, fn () => $this->url()),
            'expires_at' => $this->expires_at,
            'is_expired' => $this->isExpired(),
            'created_at' => $this->created_at,
        ];
    }

    public function withUrl(): static
    {
        $this->withUrl = true;

        return $this;
    }
}
