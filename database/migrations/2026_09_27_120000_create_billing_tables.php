<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * All monetary columns are signed 64-bit integers in nano-USD (1 USD = 1_000_000_000).
 * Model prices are nano-USD per 1 million tokens.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        Schema::create('providers', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('base_url');
            $table->string('native_format', 20)->nullable();
            $table->string('native_base_url')->nullable();
            $table->integer('markup_bps')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('provider_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('api_key');
            $table->integer('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('failure_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('public_id')->unique();
            $table->string('upstream_id');
            $table->unsignedInteger('context_window')->nullable();
            $table->bigInteger('input_price');
            $table->bigInteger('output_price');
            $table->bigInteger('cached_input_price')->nullable();
            $table->bigInteger('cache_write_price')->nullable();
            $table->integer('markup_bps')->nullable();
            $table->bigInteger('sell_input_price')->nullable();
            $table->bigInteger('sell_output_price')->nullable();
            $table->bigInteger('sell_cached_input_price')->nullable();
            $table->bigInteger('sell_cache_write_price')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('apps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->bigInteger('balance')->default(0);
            $table->integer('markup_bps')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('app_api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('key_prefix', 20);
            $table->string('key_hash', 64)->unique();
            $table->json('allowed_providers')->nullable();
            $table->json('allowed_models')->nullable();
            $table->bigInteger('spend_limit')->nullable();
            $table->bigInteger('spent')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->bigInteger('price');
            $table->bigInteger('credit');
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('app_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20);
            $table->bigInteger('amount');
            $table->bigInteger('credit');
            $table->string('status', 20)->default('pending')->index();
            $table->string('gateway', 30);
            $table->string('gateway_ref')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->bigInteger('amount');
            $table->bigInteger('balance_after');
            $table->string('description')->nullable();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('usage_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('request_id')->unique();
            $table->foreignId('app_id')->constrained()->cascadeOnDelete();
            $table->foreignId('app_api_key_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ai_model_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained()->nullOnDelete();
            $table->string('endpoint', 50);
            $table->string('model');
            $table->boolean('stream')->default(false);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('cached_input_tokens')->default(0);
            $table->unsignedInteger('cache_write_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->bigInteger('cost')->default(0);
            $table->bigInteger('charge')->default(0);
            $table->unsignedSmallInteger('status_code');
            $table->unsignedInteger('latency_ms')->default(0);
            $table->string('error', 500)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['app_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['usage_logs', 'wallet_transactions', 'orders', 'packages', 'app_api_keys', 'apps', 'ai_models', 'provider_keys', 'providers', 'settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
