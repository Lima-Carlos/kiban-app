<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kiban Core PMOC (preventive maintenance plans): a plan per customer address with its equipment, the
     * responsible technician, the frequency of the visits and the visits themselves (each one a Campo work order).
     */
    public function up(): void
    {
        Schema::create('pm_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('site_id')->nullable();
            $table->string('name', 160);
            // Data the PMOC document asks for (Brazil: Law 13.589/2018 and Ordinance 3.523/1998).
            $table->string('responsible_name', 160)->nullable();
            $table->string('responsible_registration', 60)->nullable();
            $table->unsignedInteger('area_m2')->nullable();
            $table->unsignedInteger('occupants')->nullable();
            $table->unsignedBigInteger('service_type_id')->nullable();
            $table->unsignedTinyInteger('frequency_months')->default(1);
            $table->unsignedSmallInteger('lead_days')->default(7);
            $table->date('starts_on');
            $table->date('next_due_on');
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('fd_customers')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'site_id'])->references(['tenant_id', 'id'])->on('fd_sites')->noActionOnDelete();
            $table->foreign(['tenant_id', 'service_type_id'])->references(['tenant_id', 'id'])->on('fd_service_types')->noActionOnDelete();
            $table->index(['tenant_id', 'is_active', 'next_due_on']);
        });

        Schema::create('pm_equipment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('plan_id');
            $table->string('tag', 80);
            $table->string('kind', 30);
            $table->string('room', 120)->nullable();
            $table->string('brand', 80)->nullable();
            $table->string('model', 80)->nullable();
            $table->string('serial', 80)->nullable();
            $table->unsignedInteger('capacity_btu')->nullable();
            $table->string('refrigerant', 20)->nullable();
            $table->date('installed_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'plan_id'])->references(['tenant_id', 'id'])->on('pm_plans')->cascadeOnDelete();
        });

        Schema::create('pm_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('plan_id');
            $table->date('due_on');
            // The yearly, half-yearly or quarterly activities that fall on this visit.
            $table->unsignedTinyInteger('level')->default(1);
            $table->unsignedBigInteger('work_order_id')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['plan_id', 'due_on']);
            $table->foreign(['tenant_id', 'plan_id'])->references(['tenant_id', 'id'])->on('pm_plans')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'work_order_id'])->references(['tenant_id', 'id'])->on('fd_orders')->noActionOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pm_visits');
        Schema::dropIfExists('pm_equipment');
        Schema::dropIfExists('pm_plans');
    }
};
