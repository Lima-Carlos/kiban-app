<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Companies (tenants) and who belongs to each one. All companies share one database;
     * every business table has a tenant_id and is filtered automatically (BelongsToTenant).
     */
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            // Same identifier as the customer in the Core.
            $table->uuid('uuid')->unique();
            $table->string('subdomain', 63)->unique();
            $table->string('name');
            // Last valid signed license received from the Core (works offline until it expires).
            $table->text('license_token')->nullable();
            $table->timestamp('license_fetched_at')->nullable();
            $table->timestamp('license_expires_at')->nullable();
            // Last failed attempt to reach the Core (support information).
            $table->timestamp('license_failed_at')->nullable();
            // What was last sent to the Core about the first steps, per module (avoids repeated calls).
            $table->json('onboarding_reported')->nullable();
            $table->timestamps();
        });

        Schema::create('tenant_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Role in the company, as in the Core: owner, admin or member.
            $table->string('role', 20)->default('member');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_user');
        Schema::dropIfExists('tenants');
    }
};
