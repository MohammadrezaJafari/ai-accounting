<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where an agent instance sends its reports (Telegram, Bale, email, webhook) and on which
 * days it runs. Destination settings hold bot tokens and secrets, so they are encrypted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_instances', function (Blueprint $table) {
            $table->json('run_days')->nullable()->after('run_hours');
        });

        Schema::create('agent_destinations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_instance_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('label', 100);
            $table->text('settings');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_delivered_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_destinations');

        Schema::table('agent_instances', function (Blueprint $table) {
            $table->dropColumn('run_days');
        });
    }
};
