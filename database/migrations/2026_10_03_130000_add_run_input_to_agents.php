<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An agent may ask for input on each manual run (e.g. "the link to summarize"): the label
 * is what customers are asked, and each run keeps the input it was given.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->string('run_input_label', 100)->nullable()->after('config_schema');
        });

        Schema::table('agent_runs', function (Blueprint $table) {
            $table->text('input')->nullable()->after('trigger');
        });
    }

    public function down(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->dropColumn('input');
        });

        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('run_input_label');
        });
    }
};
