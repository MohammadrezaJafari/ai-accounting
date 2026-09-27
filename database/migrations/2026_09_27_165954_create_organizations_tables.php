<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Apps move from a single user to an organization with members (owner / developer / billing).
 * Every existing customer gets a personal organization that owns their apps.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('organization_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20);
            $table->timestamps();

            $table->unique(['organization_id', 'user_id']);
        });

        Schema::create('organization_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('role', 20);
            $table->string('token', 64)->unique();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['organization_id', 'email']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('current_organization_id')->nullable()->after('role')->constrained('organizations')->nullOnDelete();
        });

        Schema::table('apps', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        // The creator no longer owns the app: removing a user must not delete the organization's apps.
        Schema::table('apps', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });
        Schema::table('apps', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

        $this->moveAppsIntoPersonalOrganizations();
    }

    public function down(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            $table->dropConstrainedForeignId('organization_id');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_organization_id');
        });
        Schema::dropIfExists('organization_invitations');
        Schema::dropIfExists('organization_user');
        Schema::dropIfExists('organizations');
    }

    private function moveAppsIntoPersonalOrganizations(): void
    {
        $now = now();
        $owners = DB::table('users')
            ->where('role', 'customer')
            ->orWhereIn('id', DB::table('apps')->select('user_id'))
            ->get(['id', 'name']);

        foreach ($owners as $owner) {
            $organizationId = DB::table('organizations')->insertGetId([
                'name' => $owner->name,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('organization_user')->insert([
                'organization_id' => $organizationId,
                'user_id' => $owner->id,
                'role' => 'owner',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('users')->where('id', $owner->id)->update(['current_organization_id' => $organizationId]);
            DB::table('apps')->where('user_id', $owner->id)->update(['organization_id' => $organizationId]);
        }
    }
};
