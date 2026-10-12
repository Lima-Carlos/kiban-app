<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kiban Frota, part 3: workshops, requests from drivers, appointments (maintenance and wash),
     * maintenance records, washes and other expenses. Every link between tables keeps the company.
     */
    public function up(): void
    {
        Schema::create('fleet_workshops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('document', 30)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('address')->nullable();
            $table->string('city', 80)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
        });

        // "Request maintenance or wash" (driver): what is needed, optional photo.
        Schema::create('fleet_service_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('vehicle_id');
            $table->string('kind', 20);
            $table->text('description');
            $table->string('photo_path')->nullable();
            // open → scheduled → done | rejected
            $table->string('status', 20)->default('open');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('response')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'vehicle_id'])->references(['tenant_id', 'id'])->on('fleet_vehicles')->cascadeOnDelete();
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('fleet_appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('vehicle_id');
            $table->string('kind', 20);
            $table->timestamp('scheduled_at');
            $table->unsignedBigInteger('workshop_id')->nullable();
            $table->string('location')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('request_id')->nullable();
            // scheduled → done | canceled
            $table->string('status', 20)->default('scheduled');
            $table->text('notes')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'vehicle_id'])->references(['tenant_id', 'id'])->on('fleet_vehicles')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'workshop_id'])->references(['tenant_id', 'id'])->on('fleet_workshops')->noActionOnDelete();
            $table->foreign(['tenant_id', 'request_id'])->references(['tenant_id', 'id'])->on('fleet_service_requests')->noActionOnDelete();
            $table->index(['tenant_id', 'scheduled_at']);
        });

        Schema::create('fleet_maintenances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('vehicle_id');
            $table->unsignedBigInteger('appointment_id')->nullable();
            $table->string('type', 20);
            // ["oil_change", "revision", "brakes"...]
            $table->json('services');
            $table->text('description')->nullable();
            $table->date('performed_on');
            $table->unsignedInteger('odometer');
            $table->unsignedBigInteger('workshop_id')->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('parts_cents')->default(0);
            $table->unsignedBigInteger('labor_cents')->default(0);
            $table->unsignedBigInteger('total_cents')->default(0);
            $table->string('invoice_path')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'vehicle_id'])->references(['tenant_id', 'id'])->on('fleet_vehicles')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'appointment_id'])->references(['tenant_id', 'id'])->on('fleet_appointments')->noActionOnDelete();
            $table->foreign(['tenant_id', 'workshop_id'])->references(['tenant_id', 'id'])->on('fleet_workshops')->noActionOnDelete();
            $table->index(['tenant_id', 'performed_on']);
        });

        Schema::create('fleet_washes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('vehicle_id');
            $table->unsignedBigInteger('appointment_id')->nullable();
            $table->string('kind', 20);
            $table->date('washed_on');
            $table->unsignedInteger('odometer')->nullable();
            $table->unsignedBigInteger('cost_cents')->default(0);
            $table->string('place')->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'vehicle_id'])->references(['tenant_id', 'id'])->on('fleet_vehicles')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'appointment_id'])->references(['tenant_id', 'id'])->on('fleet_appointments')->noActionOnDelete();
            $table->index(['tenant_id', 'washed_on']);
        });

        Schema::create('fleet_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('vehicle_id')->nullable();
            $table->string('category', 20);
            $table->string('description')->nullable();
            $table->unsignedBigInteger('amount_cents');
            $table->date('spent_on');
            $table->string('receipt_path')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['tenant_id', 'vehicle_id'])->references(['tenant_id', 'id'])->on('fleet_vehicles')->cascadeOnDelete();
            $table->index(['tenant_id', 'spent_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_expenses');
        Schema::dropIfExists('fleet_washes');
        Schema::dropIfExists('fleet_maintenances');
        Schema::dropIfExists('fleet_appointments');
        Schema::dropIfExists('fleet_service_requests');
        Schema::dropIfExists('fleet_workshops');
    }
};
