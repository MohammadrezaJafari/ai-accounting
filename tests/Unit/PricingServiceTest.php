<?php

namespace Tests\Unit;

use App\Models\AiModel;
use App\Models\App;
use App\Models\Provider;
use App\Services\PricingService;
use App\Support\Money;
use App\Support\TokenUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PricingServiceTest extends TestCase
{
    use RefreshDatabase;

    private function model(array $attributes = []): AiModel
    {
        $provider = Provider::query()->create(['slug' => 'openai', 'name' => 'OpenAI', 'base_url' => 'https://api.openai.com/v1']);

        return AiModel::query()->create($attributes + [
            'provider_id' => $provider->id,
            'name' => 'GPT',
            'public_id' => 'gpt',
            'upstream_id' => 'gpt',
            'input_price' => Money::fromUsd('2'),
            'output_price' => Money::fromUsd('8'),
            'cached_input_price' => Money::fromUsd('0.5'),
        ])->load('provider');
    }

    public function test_default_markup_is_applied_to_cost(): void
    {
        config(['billing.default_markup_bps' => 2000]);
        $pricing = app(PricingService::class);

        // 1M input ($2) + 500k cached ($0.25) + 1M output ($8) = $10.25 cost, +20% = $12.30
        $result = $pricing->calculate($this->model(), null, new TokenUsage(input: 1_000_000, cachedInput: 500_000, output: 1_000_000));

        $this->assertSame('10.25', Money::toUsd($result['cost']));
        $this->assertSame('12.30', Money::toUsd($result['charge']));
    }

    public function test_markup_precedence_app_over_explicit_over_model_over_provider(): void
    {
        $model = $this->model(['markup_bps' => 5000, 'sell_output_price' => Money::fromUsd('20')]);
        $model->provider->update(['markup_bps' => 1000]);
        $pricing = app(PricingService::class);

        $prices = $pricing->sellPrices($model);
        $this->assertSame('3.00', Money::toUsd($prices['input'])); // model markup 50%
        $this->assertSame('20.00', Money::toUsd($prices['output'])); // explicit sell price

        $app = new App(['markup_bps' => 0]);
        $app->markup_bps = 0;
        $prices = $pricing->sellPrices($model, $app);
        $this->assertSame('2.00', Money::toUsd($prices['input'])); // app deal: at cost
        $this->assertSame('8.00', Money::toUsd($prices['output']));
    }

    public function test_charge_rounds_up_to_the_next_nano(): void
    {
        config(['billing.default_markup_bps' => 0]);
        $result = app(PricingService::class)->calculate($this->model(['input_price' => 1, 'output_price' => 1]), null, new TokenUsage(input: 1));

        $this->assertSame(0, $result['cost']);
        $this->assertSame(1, $result['charge']);
    }
}
