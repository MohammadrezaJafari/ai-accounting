<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Requests per minute an API key may send to the gateway; null follows the platform default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_api_keys', function (Blueprint $table) {
            $table->unsignedInteger('rate_limit_per_minute')->nullable()->after('spend_limit_period');
        });
    }

    public function down(): void
    {
        Schema::table('app_api_keys', function (Blueprint $table) {
            $table->dropColumn('rate_limit_per_minute');
        });
    }
};
