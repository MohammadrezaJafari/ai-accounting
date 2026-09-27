<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Publishers: any organization can list its own agent service in the marketplace. Its
 * listing is reviewed by the admin before going live, and changes to a live listing wait
 * in `pending_changes` until approved. Each paid run records the publisher's share of its
 * revenue; payouts settle that balance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->foreignId('publisher_organization_id')->nullable()->after('id')->constrained('organizations')->nullOnDelete();
            $table->string('status', 20)->default('approved')->after('slug')->index();
            $table->json('pending_changes')->nullable()->after('config_schema');
            $table->string('review_note', 1000)->nullable()->after('pending_changes');
            $table->timestamp('submitted_at')->nullable()->after('review_note');
            $table->timestamp('reviewed_at')->nullable()->after('submitted_at');
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->string('publisher_name')->nullable();
            $table->string('publisher_url', 500)->nullable();
            $table->string('support_email')->nullable();
            $table->text('payout_details')->nullable();
        });

        Schema::table('agent_runs', function (Blueprint $table) {
            $table->bigInteger('publisher_share')->default(0)->after('revenue');
        });

        // A publisher's test runs use a hidden instance without an app or credits.
        Schema::table('agent_instances', function (Blueprint $table) {
            $table->boolean('is_test')->default(false)->after('is_active');
            $table->foreignId('app_id')->nullable()->change();
        });

        Schema::table('usage_logs', function (Blueprint $table) {
            $table->foreignId('app_id')->nullable()->change();
        });

        Schema::create('publisher_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('amount');
            $table->string('reference')->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamp('paid_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publisher_payouts');

        Schema::table('agent_instances', function (Blueprint $table) {
            $table->dropColumn('is_test');
        });

        Schema::table('agent_runs', function (Blueprint $table) {
            $table->dropColumn('publisher_share');
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['publisher_name', 'publisher_url', 'support_email', 'payout_details']);
        });

        Schema::table('agents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('publisher_organization_id');
            $table->dropColumn(['status', 'pending_changes', 'review_note', 'submitted_at', 'reviewed_at']);
        });
    }
};
