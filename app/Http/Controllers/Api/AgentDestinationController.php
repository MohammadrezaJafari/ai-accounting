<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AgentDestinationResource;
use App\Models\AgentDestination;
use App\Models\AgentInstance;
use App\Services\Agents\Delivery\ReportDelivery;
use App\Support\DeliveryChannel;
use App\Support\OrganizationPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Where an agent instance delivers its reports: Telegram or Bale chats, email, or a webhook.
 */
class AgentDestinationController extends Controller
{
    use Concerns;

    public function index(Request $request, AgentInstance $agentInstance): AnonymousResourceCollection
    {
        return AgentDestinationResource::collection($this->ownedInstance($request, $agentInstance)->destinations()->oldest('id')->get());
    }

    public function store(Request $request, AgentInstance $agentInstance): JsonResponse
    {
        $this->authorizeTo(OrganizationPermission::ManageApps);
        $instance = $this->ownedInstance($request, $agentInstance);

        $request->validate(['type' => ['required', Rule::enum(DeliveryChannel::class)]]);
        $channel = DeliveryChannel::from($request->input('type'));
        $data = $this->validated($request, $channel, true);

        $destination = $instance->destinations()->create([
            'type' => $channel,
            'label' => $data['label'] ?? $channel->label(),
            'settings' => $this->settings($channel, $data['settings']),
            'is_active' => $data['is_active'] ?? true,
        ]);

        return (new AgentDestinationResource($destination))->response()->setStatusCode(201);
    }

    public function update(Request $request, AgentDestination $agentDestination): AgentDestinationResource
    {
        $this->authorizeTo(OrganizationPermission::ManageApps);
        $destination = $this->owned($request, $agentDestination);
        $data = $this->validated($request, $destination->type, false);

        if (isset($data['settings'])) {
            $data['settings'] = $this->settings($destination->type, $data['settings'], $destination->settings);
        }

        $destination->update($data);

        return new AgentDestinationResource($destination);
    }

    public function destroy(Request $request, AgentDestination $agentDestination): JsonResponse
    {
        $this->authorizeTo(OrganizationPermission::ManageApps);
        $this->owned($request, $agentDestination)->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Send a short test message.
     */
    public function test(Request $request, AgentDestination $agentDestination, ReportDelivery $delivery): JsonResponse
    {
        $this->authorizeTo(OrganizationPermission::ManageApps);
        $error = $delivery->test($this->owned($request, $agentDestination));

        return response()->json(['ok' => $error === null, 'error' => $error]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, DeliveryChannel $channel, bool $creating): array
    {
        $settingsRules = collect($channel->rules())->mapWithKeys(fn ($rules, $key) => ["settings.{$key}" => $rules])->all();

        // On update an empty bot token keeps the stored one.
        if (! $creating && $channel->isMessenger()) {
            $settingsRules['settings.bot_token'] = ['nullable', 'string', 'max:200', 'regex:/^\d+:[A-Za-z0-9_-]{20,}$/'];
        }

        return $request->validate([
            'label' => ['nullable', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'settings' => [$creating ? 'required' : 'sometimes', 'array'],
            ...($creating || $request->has('settings') ? $settingsRules : []),
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    private function settings(DeliveryChannel $channel, array $input, array $current = []): array
    {
        return match ($channel) {
            DeliveryChannel::Telegram, DeliveryChannel::Bale => array_filter([
                'chat_id' => trim($input['chat_id']),
                'bot_token' => trim((string) ($input['bot_token'] ?? '')) ?: ($current['bot_token'] ?? null),
            ]),
            DeliveryChannel::Email => ['emails' => array_values(array_unique(array_map(fn ($email) => Str::lower(trim($email)), $input['emails'])))],
            DeliveryChannel::Webhook => ['url' => trim($input['url']), 'secret' => $current['secret'] ?? Str::random(40)],
            DeliveryChannel::Rahap => ['url' => trim($input['url'])],
        };
    }

    private function ownedInstance(Request $request, AgentInstance $instance): AgentInstance
    {
        abort_unless($instance->organization_id === $this->organization($request)->id, 404);

        return $instance;
    }

    private function owned(Request $request, AgentDestination $destination): AgentDestination
    {
        $this->ownedInstance($request, $destination->instance);

        return $destination;
    }
}
