<?php

namespace App\Services\Agents;

use App\Support\AgentKind;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * A publisher describes its agent in a JSON manifest so the listing does not have to be typed
 * in by hand: name, tagline, description, icon, category, publisher {name, url}, unit_name,
 * max_units_per_run, endpoint_url and config_schema (see ConfigSchema).
 */
class AgentManifest
{
    public function __construct(private UrlGuard $guard) {}

    /**
     * Listing attributes from the manifest at `$url`. A publisher's manifest must be on the
     * public internet (`$publicOnly`).
     *
     * @return array<string, mixed>
     *
     * @throws AgentException with a message for the admin or publisher
     */
    public function fetch(string $url, bool $publicOnly = false): array
    {
        if ($publicOnly) {
            $this->guard->assertPublisherUrl($url);
        }

        try {
            $response = Http::timeout(15)->withOptions(['allow_redirects' => false])->acceptJson()->get($url);
        } catch (ConnectionException) {
            throw new AgentException('manifest در دسترس نبود.');
        }

        if (! $response->successful() || ! is_array($response->json())) {
            throw new AgentException("manifest دریافت نشد (پاسخ {$response->status()}).");
        }

        return $this->parse($response->json());
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    public function parse(array $manifest): array
    {
        $validator = Validator::make($manifest, [
            'name' => ['required', 'string', 'max:100'],
            'tagline' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'icon' => ['nullable', 'string', 'max:50'],
            'category' => ['nullable', 'string', 'max:50'],
            'kind' => ['nullable', Rule::enum(AgentKind::class)],
            'publisher.name' => ['nullable', 'string', 'max:255'],
            'publisher.url' => ['nullable', 'url', 'max:500'],
            'unit_name' => ['nullable', 'string', 'max:50'],
            'max_units_per_run' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'endpoint_url' => ['nullable', 'url:http,https', 'max:500'],
            'config_schema' => ['nullable', 'array', 'max:50'],
        ]);

        if ($validator->fails()) {
            throw new AgentException('manifest معتبر نیست: '.$validator->errors()->first());
        }

        try {
            $schema = ConfigSchema::define($manifest['config_schema'] ?? []);
        } catch (InvalidArgumentException $e) {
            throw new AgentException('پارامترهای manifest معتبر نیستند: '.$e->getMessage());
        }

        return array_filter([
            ...Arr::only($manifest, ['name', 'tagline', 'description', 'icon', 'category', 'kind', 'unit_name', 'max_units_per_run', 'endpoint_url']),
            'publisher_name' => data_get($manifest, 'publisher.name'),
            'publisher_url' => data_get($manifest, 'publisher.url'),
            'config_schema' => $schema,
        ], fn ($value) => $value !== null);
    }
}
