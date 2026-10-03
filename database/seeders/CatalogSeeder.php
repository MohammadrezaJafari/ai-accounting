<?php

namespace Database\Seeders;

use App\Models\AiModel;
use App\Models\Package;
use App\Models\Provider;
use App\Models\ProviderKey;
use App\Support\Money;
use Illuminate\Database\Seeder;

/**
 * Providers, models and packages to start from. Prices are USD per 1M tokens as
 * published by each provider at the time of writing — verify them in the admin panel.
 */
class CatalogSeeder extends Seeder
{
    /**
     * Card tagline and whether the model is featured on the customer dashboard.
     *
     * @var array<string, array{0: string, 1: bool}>
     */
    private const DESCRIPTIONS = [
        'gpt-5' => ['استدلال و کدنویسی پیشرفته', true],
        'gpt-5-mini' => ['سریع و اقتصادی برای کارهای روزمره', false],
        'gpt-5-nano' => ['ارزان‌ترین مدل OpenAI برای حجم بالا', false],
        'gpt-4.1' => ['پنجرهٔ زمینهٔ یک میلیون توکنی', false],
        'gpt-4.1-mini' => ['تعادل سرعت و کیفیت', false],
        'gpt-4o' => ['چندوجهی: متن و تصویر', false],
        'gpt-4o-mini' => ['سبک و ارزان', true],
        'claude-opus-4-1' => ['استدلال و تحلیل پیشرفته', true],
        'claude-sonnet-4-5' => ['کدنویسی و ایجنت‌ها', true],
        'claude-haiku-4-5' => ['سریع‌ترین مدل Claude', false],
        'gemini-2.5-pro' => ['تحلیل اسناد و زمینهٔ بلند', true],
        'gemini-2.5-flash' => ['سریع با قیمت مناسب', false],
        'gemini-2.5-flash-lite' => ['اقتصادی برای حجم بالا', false],
        'deepseek-chat' => ['ارزان و قوی برای گفت‌وگو', true],
        'deepseek-reasoner' => ['مدل استدلالی DeepSeek', false],
        'grok-4' => ['مدل پرچم‌دار xAI', false],
    ];

    public function run(): void
    {
        $providers = [
            'openai' => ['name' => 'OpenAI', 'base_url' => 'https://api.openai.com/v1'],
            'anthropic' => [
                'name' => 'Anthropic',
                'base_url' => 'https://api.anthropic.com/v1',
                'native_format' => Provider::NATIVE_ANTHROPIC,
                'native_base_url' => 'https://api.anthropic.com',
            ],
            'google' => ['name' => 'Google Gemini', 'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai'],
            'deepseek' => ['name' => 'DeepSeek', 'base_url' => 'https://api.deepseek.com/v1'],
            'xai' => ['name' => 'xAI', 'base_url' => 'https://api.x.ai/v1'],
            // Iranian OpenAI-compatible reseller of every model above; handy when the official APIs are out of reach.
            'gapgpt' => ['name' => 'GapGPT', 'base_url' => config('services.gapgpt.base_url')],
        ];

        foreach ($providers as $slug => $attributes) {
            Provider::query()->updateOrCreate(['slug' => $slug], $attributes);
        }

        // [provider, name, public id, upstream id, context, input, output, cached input, cache write]
        $models = [
            ['openai', 'GPT-5', 'gpt-5', 'gpt-5', 400000, '1.25', '10', '0.125', null],
            ['openai', 'GPT-5 mini', 'gpt-5-mini', 'gpt-5-mini', 400000, '0.25', '2', '0.025', null],
            ['openai', 'GPT-5 nano', 'gpt-5-nano', 'gpt-5-nano', 400000, '0.05', '0.4', '0.005', null],
            ['openai', 'GPT-4.1', 'gpt-4.1', 'gpt-4.1', 1047576, '2', '8', '0.5', null],
            ['openai', 'GPT-4.1 mini', 'gpt-4.1-mini', 'gpt-4.1-mini', 1047576, '0.4', '1.6', '0.1', null],
            ['openai', 'GPT-4o', 'gpt-4o', 'gpt-4o', 128000, '2.5', '10', '1.25', null],
            ['openai', 'GPT-4o mini', 'gpt-4o-mini', 'gpt-4o-mini', 128000, '0.15', '0.6', '0.075', null],
            ['anthropic', 'Claude Opus 4.1', 'claude-opus-4-1', 'claude-opus-4-1', 200000, '15', '75', '1.5', '18.75'],
            ['anthropic', 'Claude Sonnet 4.5', 'claude-sonnet-4-5', 'claude-sonnet-4-5', 200000, '3', '15', '0.3', '3.75'],
            ['anthropic', 'Claude Haiku 4.5', 'claude-haiku-4-5', 'claude-haiku-4-5', 200000, '1', '5', '0.1', '1.25'],
            ['google', 'Gemini 2.5 Pro', 'gemini-2.5-pro', 'gemini-2.5-pro', 1048576, '1.25', '10', '0.31', null],
            ['google', 'Gemini 2.5 Flash', 'gemini-2.5-flash', 'gemini-2.5-flash', 1048576, '0.3', '2.5', '0.075', null],
            ['google', 'Gemini 2.5 Flash-Lite', 'gemini-2.5-flash-lite', 'gemini-2.5-flash-lite', 1048576, '0.1', '0.4', '0.025', null],
            ['deepseek', 'DeepSeek Chat', 'deepseek-chat', 'deepseek-chat', 128000, '0.28', '0.42', '0.028', null],
            ['deepseek', 'DeepSeek Reasoner', 'deepseek-reasoner', 'deepseek-reasoner', 128000, '0.28', '0.42', '0.028', null],
            ['xai', 'Grok 4', 'grok-4', 'grok-4', 256000, '3', '15', '0.75', null],
        ];

        $ids = Provider::query()->pluck('id', 'slug');
        $this->seedGapGptKey($ids['gapgpt']);

        foreach ($models as [$provider, $name, $publicId, $upstreamId, $context, $input, $output, $cached, $cacheWrite]) {
            AiModel::query()->updateOrCreate(['public_id' => $publicId], [
                'provider_id' => $ids[config('services.gapgpt.route_all_models') ? 'gapgpt' : $provider],
                'name' => $name,
                'upstream_id' => $upstreamId,
                'context_window' => $context,
                'description' => self::DESCRIPTIONS[$publicId][0] ?? null,
                'is_featured' => self::DESCRIPTIONS[$publicId][1] ?? false,
                'input_price' => Money::fromUsd($input),
                'output_price' => Money::fromUsd($output),
                'cached_input_price' => Money::fromUsd($cached),
                'cache_write_price' => Money::fromUsd($cacheWrite),
            ]);
        }

        $packages = [
            ['شروع', 'برای امتحان و پروژه‌های کوچک', '5', '5', 1],
            ['حرفه‌ای', '۵٪ اعتبار هدیه', '20', '21', 2],
            ['سازمانی', '۱۰٪ اعتبار هدیه', '100', '110', 3],
        ];

        foreach ($packages as [$name, $description, $price, $credit, $order]) {
            Package::query()->updateOrCreate(['name' => $name], [
                'description' => $description,
                'price' => Money::fromUsd($price),
                'credit' => Money::fromUsd($credit),
                'sort_order' => $order,
            ]);
        }
    }

    /**
     * Stores GAPGPT_API_KEY as the provider's upstream key, so a fresh install can be tested end to end.
     */
    private function seedGapGptKey(int $providerId): void
    {
        $apiKey = config('services.gapgpt.api_key');

        if (blank($apiKey)) {
            return;
        }

        ProviderKey::query()->updateOrCreate(['provider_id' => $providerId, 'name' => 'GapGPT'], [
            'api_key' => $apiKey,
            'is_active' => true,
        ]);
    }
}
