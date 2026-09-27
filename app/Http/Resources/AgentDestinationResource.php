<?php

namespace App\Http\Resources;

use App\Support\DeliveryChannel;
use App\Support\OrganizationPermission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * A delivery destination. Bot tokens are never returned; the webhook signing secret only to
 * members who manage agents.
 */
class AgentDestinationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $token = $this->setting('bot_token');

        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'label' => $this->label,
            'settings' => match ($this->type) {
                DeliveryChannel::Telegram, DeliveryChannel::Bale => [
                    'chat_id' => $this->setting('chat_id'),
                    'bot_token' => $token ? Str::before($token, ':').':…'.substr($token, -4) : null,
                ],
                DeliveryChannel::Email => ['emails' => $this->setting('emails', [])],
                DeliveryChannel::Webhook => [
                    'url' => $this->setting('url'),
                    'secret' => $this->when(Gate::allows(OrganizationPermission::ManageApps->value), fn () => $this->setting('secret')),
                ],
            },
            'is_active' => $this->is_active,
            'last_delivered_at' => $this->last_delivered_at,
            'last_error' => $this->last_error,
            'created_at' => $this->created_at,
        ];
    }
}
