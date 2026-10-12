<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One system with many modules: each person has, per module, a role or no access at all
     * (owners and administrators of the company have every module). Access profiles ("Logistics", "HR",
     * "Driver"...) group the roles of several modules, so a new person is set up in one click.
     */
    public function up(): void
    {
        Schema::create('access_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            // {"frota": "manager", "ponto": "employee"}
            $table->json('roles');
            // Suggested profile created by the app (key), null when made by the company.
            $table->string('suggestion', 40)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('module_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('module', 50);
            $table->string('role', 30);
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id', 'module']);
        });

        Schema::table('tenant_user', function (Blueprint $table) {
            $table->unsignedBigInteger('access_profile_id')->nullable()->after('role');
            $table->foreign(['tenant_id', 'access_profile_id'])->references(['tenant_id', 'id'])->on('access_profiles')->noActionOnDelete();
        });

        // Roles given in the old "Fleet team" become Frota access.
        if (Schema::hasTable('fleet_members')) {
            DB::statement("INSERT INTO module_access (tenant_id, user_id, module, role, created_at, updated_at)
                SELECT tenant_id, user_id, 'frota', role, created_at, updated_at FROM fleet_members");
            Schema::drop('fleet_members');
        }
    }

    public function down(): void
    {
        Schema::table('tenant_user', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'access_profile_id']);
            $table->dropColumn('access_profile_id');
        });
        Schema::dropIfExists('module_access');
        Schema::dropIfExists('access_profiles');
    }
};
