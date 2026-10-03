<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The publisher ledger also records deposits: money a publisher moves from one of its apps'
 * wallets to cover model costs that pushed its balance below zero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('publisher_payouts', function (Blueprint $table) {
            $table->string('type', 20)->default('payout')->after('organization_id')->index();
            $table->foreignId('app_id')->nullable()->after('type')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('publisher_payouts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('app_id');
            $table->dropIndex(['type']);
            $table->dropColumn('type');
        });
    }
};
