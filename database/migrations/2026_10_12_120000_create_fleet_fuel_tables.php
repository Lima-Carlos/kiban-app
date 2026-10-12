<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kiban Frota, part 2: team roles and fuel-ups with an authorization code.
     */
    public function up(): void
    {
        // Role of each person in the Frota (owner/admin of the company are always managers).
        Schema::create('fleet_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20);
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id']);
        });

        Schema::create('fleet_fuel_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('vehicle_id');
            $table->unsignedBigInteger('driver_id')->nullable();
            $table->unsignedBigInteger('station_id')->nullable();
            // authorized (code generated, waiting) → completed | expired | canceled
            $table->string('status', 20);
            // Single-use code the station asks for. Unique while it is open (see the index below).
            $table->string('code', 10)->nullable();
            $table->timestamp('code_expires_at')->nullable();
            $table->timestamp('station_checked_at')->nullable();
            $table->unsignedInteger('odometer');
            $table->string('odometer_photo_path')->nullable();
            $table->string('fuel', 20)->nullable();
            $table->decimal('volume', 10, 3)->nullable();
            $table->unsignedBigInteger('total_cents')->nullable();
            $table->decimal('unit_price', 10, 3)->nullable();
            $table->boolean('full_tank')->default(true);
            $table->string('receipt_path')->nullable();
            // Full tank to full tank: distance, volume used and the result (e.g. km/l).
            $table->unsignedInteger('distance')->nullable();
            $table->decimal('segment_volume', 10, 3)->nullable();
            $table->decimal('consumption', 8, 3)->nullable();
            // Warnings found when finishing (over tank capacity, wrong fuel, high consumption...).
            $table->json('flags')->nullable();
            $table->timestamp('fueled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'vehicle_id'])->references(['tenant_id', 'id'])->on('fleet_vehicles')->cascadeOnDelete();
            // Drivers and stations with fuel-ups are never deleted, only deactivated.
            $table->foreign(['tenant_id', 'driver_id'])->references(['tenant_id', 'id'])->on('fleet_drivers')->noActionOnDelete();
            $table->foreign(['tenant_id', 'station_id'])->references(['tenant_id', 'id'])->on('fleet_fuel_stations')->noActionOnDelete();
            $table->index(['tenant_id', 'status']);
            $table->index(['vehicle_id', 'fueled_at']);
        });

        DB::statement("CREATE UNIQUE INDEX fleet_fuel_ups_open_code_unique ON fleet_fuel_ups (tenant_id, code) WHERE status = 'authorized'");
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_fuel_ups');
        Schema::dropIfExists('fleet_members');
    }
};
