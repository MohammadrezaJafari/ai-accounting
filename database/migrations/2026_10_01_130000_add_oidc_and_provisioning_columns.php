<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sign-in with the organization's identity provider and provisioning from the Rahap Hub:
 * users are keyed by (oidc_issuer, oidc_subject), each dashboard token remembers the identity
 * provider session (`sid`) it came from, and an organization remembers its Hub key per tenant.
 * Everything is nullable: a single installation without the Hub leaves it empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('oidc_issuer')->nullable()->after('email');
            $table->string('oidc_subject')->nullable()->after('oidc_issuer');

            $table->unique(['oidc_issuer', 'oidc_subject']);
            $table->index('oidc_subject');
        });

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->string('oidc_sid')->nullable()->index();
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->string('tenant', 100)->nullable()->after('id');
            $table->string('external_key')->nullable()->after('tenant');

            $table->unique(['tenant', 'external_key']);
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropUnique(['tenant', 'external_key']);
            $table->dropColumn(['tenant', 'external_key']);
        });

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropIndex(['oidc_sid']);
            $table->dropColumn('oidc_sid');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['oidc_issuer', 'oidc_subject']);
            $table->dropIndex(['oidc_subject']);
            $table->dropColumn(['oidc_issuer', 'oidc_subject']);
        });
    }
};
