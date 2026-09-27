<?php

namespace App\Services;

use App\Models\AiModel;
use App\Models\App;
use App\Support\TokenUsage;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Resolves what a request costs us and what we charge for it.
 *
 * Customer price per token category is, in order of precedence:
 *   1. cost × (1 + app markup)           — a custom deal set on the app
 *   2. the model's explicit sell price   — a fixed price set by the admin
 *   3. cost × (1 + model / provider / default markup)
 */
class PricingService
{
    public const CATEGORIES = ['input', 'cached_input', 'cache_write', 'output'];

    public function __construct(private SettingsService $settings) {}

    public function markupBps(AiModel $model, ?App $app = null): int
    {
        return $app?->markup_bps
            ?? $model->markup_bps
            ?? $model->provider?->markup_bps
            ?? (int) $this->settings->get('default_markup_bps');
    }

    /**
     * @return array<string, int> nano-USD per 1M tokens
     */
    public function costPrices(AiModel $model): array
    {
        return [
            'input' => $model->input_price,
            'cached_input' => $model->cached_input_price ?? $model->input_price,
            'cache_write' => $model->cache_write_price ?? $model->input_price,
            'output' => $model->output_price,
        ];
    }

    /**
     * @return array<string, int> nano-USD per 1M tokens
     */
    public function sellPrices(AiModel $model, ?App $app = null): array
    {
        $markup = $this->markupBps($model, $app);
        $useExplicit = $app?->markup_bps === null;

        $prices = [];
        foreach ($this->costPrices($model) as $category => $cost) {
            $explicit = $model->{"sell_{$category}_price"};
            $prices[$category] = $useExplicit && $explicit !== null ? $explicit : $this->applyMarkup($cost, $markup);
        }

        return $prices;
    }

    /**
     * @return array{cost: int, charge: int}
     */
    public function calculate(AiModel $model, ?App $app, TokenUsage $usage): array
    {
        return [
            'cost' => $this->total($this->costPrices($model), $usage, RoundingMode::HalfUp),
            'charge' => $this->total($this->sellPrices($model, $app), $usage, RoundingMode::Up),
        ];
    }

    private function applyMarkup(int $price, int $markupBps): int
    {
        return BigDecimal::of($price)
            ->multipliedBy(10000 + $markupBps)
            ->dividedBy(10000, 0, RoundingMode::HalfUp)
            ->toInt();
    }

    private function total(array $prices, TokenUsage $usage, RoundingMode $rounding): int
    {
        $tokens = [
            'input' => $usage->input,
            'cached_input' => $usage->cachedInput,
            'cache_write' => $usage->cacheWrite,
            'output' => $usage->output,
        ];

        $sum = BigDecimal::zero();
        foreach ($tokens as $category => $count) {
            $sum = $sum->plus(BigDecimal::of($prices[$category])->multipliedBy($count));
        }

        return $sum->dividedBy(1_000_000, 0, $rounding)->toInt();
    }
}
