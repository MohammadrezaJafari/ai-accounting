<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agents say what kind they are: a scheduled report (everything so far) or an interactive one
 * that lives in a messenger. Company OS reads this to decide where "add to my workspace" goes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('agents', 'kind')) {
            Schema::table('agents', function (Blueprint $table) {
                $table->string('kind', 20)->default('report')->after('driver');
            });
        }
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
