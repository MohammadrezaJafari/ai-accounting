<?php

use App\Services\Agents\NewsMonitorAgent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agents become marketplace listings: an HTTP service (ours or a third-party publisher's)
 * defined in the admin panel with the parameters customers fill in. The platform keeps
 * credits, schedules, model access and delivery; the agent only does the work.
 *
 * Runs get a short-lived token the agent uses to call our models and to post its result later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->string('driver', 20)->default('http')->after('slug');
            $table->string('endpoint_url', 500)->nullable()->after('driver');
            $table->text('signing_secret')->nullable()->after('endpoint_url');
            $table->unsignedSmallInteger('timeout_seconds')->default(60)->after('signing_secret');
            $table->unsignedSmallInteger('run_deadline_minutes')->default(15)->after('timeout_seconds');
            $table->json('config_schema')->nullable()->after('run_deadline_minutes');
            $table->string('tagline')->nullable()->after('name');
            $table->string('icon', 50)->nullable()->after('tagline');
            $table->string('category', 50)->nullable()->after('icon');
            $table->string('publisher_name')->nullable()->after('category');
            $table->string('publisher_url', 500)->nullable()->after('publisher_name');
            $table->unsignedTinyInteger('revenue_share')->default(0)->after('publisher_url');
            $table->unsignedInteger('max_units_per_run')->default(1)->after('unit_name');
            $table->json('allowed_models')->nullable()->after('model');
            $table->string('model')->nullable()->change();
        });

        Schema::table('agent_instances', function (Blueprint $table) {
            $table->boolean('notify_empty')->default(false)->after('run_days');
        });

        Schema::table('agent_runs', function (Blueprint $table) {
            $table->string('token_hash', 64)->nullable()->unique()->after('trigger');
            $table->timestamp('deadline_at')->nullable()->after('started_at');
            $table->json('data')->nullable()->after('report');
        });

        DB::table('agents')->where('slug', 'news-monitor')->update([
            'driver' => 'builtin',
            'config_schema' => json_encode(NewsMonitorAgent::CONFIG_SCHEMA, JSON_UNESCAPED_UNICODE),
            'icon' => 'feed',
            'category' => 'پایش و گزارش',
            'tagline' => 'خبرهای تازهٔ منابع شما، خلاصه و دسته‌بندی‌شده',
        ]);

        // "Tell me when there is nothing new" is a delivery option of every agent now, not a news setting.
        DB::table('agent_instances')->orderBy('id')->each(function (object $instance) {
            $config = json_decode($instance->config, true) ?: [];

            DB::table('agent_instances')->where('id', $instance->id)->update([
                'notify_empty' => (bool) ($config['notify_empty'] ?? false),
                'config' => json_encode(array_diff_key($config, ['notify_empty' => true]), JSON_UNESCAPED_UNICODE),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->dropUnique(['token_hash']);
            $table->dropColumn(['token_hash', 'deadline_at', 'data']);
        });

        Schema::table('agent_instances', function (Blueprint $table) {
            $table->dropColumn('notify_empty');
        });

        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn([
                'driver', 'endpoint_url', 'signing_secret', 'timeout_seconds', 'run_deadline_minutes', 'config_schema',
                'tagline', 'icon', 'category', 'publisher_name', 'publisher_url', 'revenue_share', 'max_units_per_run', 'allowed_models',
            ]);
        });
    }
};
