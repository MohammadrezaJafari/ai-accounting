<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A publisher pays for the model calls of its agent: each run of a publisher's agent (paid,
 * empty, failed or a test) records its model cost as `publisher_cost`, and the publisher
 * earns `publisher_share - publisher_cost`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->bigInteger('publisher_cost')->default(0)->after('publisher_share');
        });

        DB::table('agent_runs')
            ->whereIn('agent_id', DB::table('agents')->whereNotNull('publisher_organization_id')->select('id'))
            ->update(['publisher_cost' => DB::raw('cost')]);
    }

    public function down(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->dropColumn('publisher_cost');
        });
    }
};
