<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agents are products sold per unit (e.g. one news report) instead of per token.
 * Organizations buy unit packages with an app wallet; every run that delivers a unit
 * uses one credit. The run's model calls are logged at cost (charge 0) so the admin
 * sees the real cost and margin of each unit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('unit_name', 50);
            $table->string('model');
            $table->bigInteger('max_cost_per_run')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('agent_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('units');
            $table->bigInteger('price');
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('agent_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->integer('units')->default(0);
            // What the remaining units were paid for (nano-USD); a used unit is worth value / units.
            $table->bigInteger('value')->default(0);
            $table->timestamps();

            $table->unique(['organization_id', 'agent_id']);
        });

        Schema::create('agent_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('app_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('config');
            $table->json('run_hours')->nullable();
            $table->json('state')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('agent_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_instance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->index();
            $table->string('trigger', 20);
            $table->unsignedInteger('units')->default(0);
            $table->bigInteger('revenue')->default(0);
            $table->bigInteger('cost')->default(0);
            $table->unsignedInteger('items_found')->default(0);
            $table->longText('report')->nullable();
            $table->string('error', 500)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('agent_credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('app_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('agent_run_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('agent_package_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20);
            $table->integer('units');
            $table->bigInteger('value');
            $table->string('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('usage_logs', function (Blueprint $table) {
            $table->foreignId('agent_run_id')->nullable()->after('app_api_key_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('usage_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agent_run_id');
        });

        foreach (['agent_credit_transactions', 'agent_runs', 'agent_instances', 'agent_credits', 'agent_packages', 'agents'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
