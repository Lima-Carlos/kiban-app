<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rotina: what an hour of each person costs the company (only the manager sees it) and, per project, whether
     * the hours are charged to the client and at what price, so the summary shows cost, revenue and margin.
     */
    public function up(): void
    {
        Schema::create('ro_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('hour_cents');
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id']);
        });

        Schema::table('ro_projects', function (Blueprint $table) {
            $table->boolean('billable')->default(false);
            $table->unsignedInteger('hour_price_cents')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ro_projects', function (Blueprint $table) {
            $table->dropColumn(['billable', 'hour_price_cents']);
        });

        Schema::dropIfExists('ro_rates');
    }
};
