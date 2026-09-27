<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Publishers pay for their agents' model calls, so they set the cap per run themselves
 * (`max_cost_per_run`) up to a ceiling the admin allows: this column, or the platform default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->bigInteger('max_cost_ceiling')->nullable()->after('max_cost_per_run');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('max_cost_ceiling');
        });
    }
};
