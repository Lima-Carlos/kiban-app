<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kiban Core Despesas (expense claims): what a person paid for the company (toll, parking, meal, hotel, ride,
     * material...) with the receipt photo, approved or refused by the manager and paid back.
     */
    public function up(): void
    {
        Schema::create('ex_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('key', 30)->nullable();
            $table->string('name', 80);
            // Above this value the claim is flagged for the manager (never blocked).
            $table->unsignedInteger('limit_cents')->nullable();
            $table->boolean('receipt_required')->default(true);
            // With the Frota: an approved claim with a vehicle becomes an expense of the vehicle in this category.
            $table->string('fleet_category', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
        });

        Schema::create('ex_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('category_id');
            $table->date('spent_on');
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3);
            $table->string('description', 255);
            $table->string('receipt_path')->nullable();
            $table->unsignedBigInteger('work_order_id')->nullable();
            $table->unsignedBigInteger('vehicle_id')->nullable();
            // pending, approved, rejected, paid
            $table->string('status', 10)->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 255)->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->unsignedBigInteger('fleet_expense_id')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'category_id'])->references(['tenant_id', 'id'])->on('ex_categories')->noActionOnDelete();
            $table->foreign(['tenant_id', 'work_order_id'])->references(['tenant_id', 'id'])->on('fd_orders')->noActionOnDelete();
            $table->foreign(['tenant_id', 'vehicle_id'])->references(['tenant_id', 'id'])->on('fleet_vehicles')->noActionOnDelete();
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'user_id', 'spent_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ex_claims');
        Schema::dropIfExists('ex_categories');
    }
};
