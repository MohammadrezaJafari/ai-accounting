<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\Provider;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_gapgpt_is_seeded_without_a_key_and_models_keep_their_providers(): void
    {
        config(['services.gapgpt.api_key' => null, 'services.gapgpt.route_all_models' => false]);

        $this->seed(CatalogSeeder::class);

        $gapgpt = Provider::query()->where('slug', 'gapgpt')->sole();
        $this->assertSame('https://api.gapgpt.app/v1', $gapgpt->base_url);
        $this->assertSame(0, $gapgpt->keys()->count());
        $this->assertSame('openai', AiModel::query()->where('public_id', 'gpt-4o-mini')->sole()->provider->slug);
    }

    public function test_every_model_can_be_routed_through_gapgpt_with_its_key(): void
    {
        config(['services.gapgpt.api_key' => 'gap-secret', 'services.gapgpt.route_all_models' => true]);

        $this->seed(CatalogSeeder::class);
        $this->seed(CatalogSeeder::class);

        $gapgpt = Provider::query()->where('slug', 'gapgpt')->sole();
        $this->assertSame('gap-secret', $gapgpt->keys()->sole()->api_key);
        $this->assertSame(AiModel::query()->count(), AiModel::query()->where('provider_id', $gapgpt->id)->count());
    }
}
