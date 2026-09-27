<?php

namespace App\Models;

use App\Support\DeliveryChannel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where an agent instance sends its reports. `settings` depend on the channel
 * (chat id and bot token, email addresses, webhook URL and signing secret) and are encrypted.
 */
#[Fillable(['agent_instance_id', 'type', 'label', 'settings', 'is_active', 'last_delivered_at', 'last_error'])]
#[Hidden(['settings'])]
class AgentDestination extends Model
{
    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return [
            'type' => DeliveryChannel::class,
            'settings' => 'encrypted:array',
            'is_active' => 'boolean',
            'last_delivered_at' => 'datetime',
        ];
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(AgentInstance::class, 'agent_instance_id');
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }
}
