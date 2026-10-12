<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kiban Frota, part 1: settings, vehicles, odometer readings, documents, drivers and fuel stations.
     * Every table belongs to a company (tenant_id), and links between tables use (tenant_id, id),
     * so the database itself refuses to mix companies.
     */
    public function up(): void
    {
        Schema::create('fleet_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            // km or mi; l or gal. Locked once there are records (values are stored as typed).
            $table->string('distance_unit', 5)->default('km');
            $table->string('volume_unit', 5)->default('l');
            $table->string('currency', 3)->default('BRL');
            // Warn before oil change / revision: this many km or days before.
            $table->unsignedInteger('alert_distance')->default(500);
            $table->unsignedInteger('alert_days')->default(15);
            $table->timestamps();
        });

        Schema::create('fleet_drivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // Same e-mail as the person's Kiban account: links the login to this driver automatically.
            $table->string('email')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phone', 30)->nullable();
            $table->string('document', 30)->nullable();
            $table->string('license_number', 30)->nullable();
            $table->string('license_category', 10)->nullable();
            $table->date('license_expires_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'email']);
            $table->unique(['tenant_id', 'user_id']);
        });

        Schema::create('fleet_vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('plate', 12);
            $table->string('vin', 30)->nullable();
            $table->string('registration', 30)->nullable();
            $table->string('brand', 60);
            $table->string('model', 80);
            $table->unsignedSmallInteger('manufacture_year')->nullable();
            $table->unsignedSmallInteger('model_year')->nullable();
            $table->string('color', 40)->nullable();
            $table->string('type', 20);
            $table->string('fuel_type', 20);
            $table->decimal('tank_capacity', 8, 2)->nullable();
            $table->string('photo_path')->nullable();
            $table->string('status', 20)->default('active');
            // Current odometer (always the highest reading) and when it last changed.
            $table->unsignedInteger('odometer')->default(0);
            $table->timestamp('odometer_updated_at')->nullable();
            // Oil change and revision: next due (distance and/or date) and the interval to calculate the following one.
            $table->unsignedInteger('next_oil_change_odometer')->nullable();
            $table->date('next_oil_change_on')->nullable();
            $table->unsignedInteger('oil_change_interval_distance')->nullable();
            $table->unsignedSmallInteger('oil_change_interval_months')->nullable();
            $table->unsignedInteger('next_revision_odometer')->nullable();
            $table->date('next_revision_on')->nullable();
            $table->unsignedInteger('revision_interval_distance')->nullable();
            $table->unsignedSmallInteger('revision_interval_months')->nullable();
            // Plate and chassis cannot change freely after the first record (avoids reusing one vehicle slot for another car).
            $table->timestamp('identity_locked_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'plate']);
            $table->index(['tenant_id', 'status']);
        });
        // Chassis unique per company when filled.
        DB::statement('CREATE UNIQUE INDEX fleet_vehicles_tenant_vin_unique ON fleet_vehicles (tenant_id, vin) WHERE vin IS NOT NULL');

        Schema::create('fleet_driver_vehicle', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('driver_id');
            $table->unsignedBigInteger('vehicle_id');
            $table->timestamps();

            $table->foreign(['tenant_id', 'driver_id'])->references(['tenant_id', 'id'])->on('fleet_drivers')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'vehicle_id'])->references(['tenant_id', 'id'])->on('fleet_vehicles')->cascadeOnDelete();
            $table->unique(['driver_id', 'vehicle_id']);
        });

        Schema::create('fleet_odometer_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('vehicle_id');
            $table->unsignedInteger('value');
            // What produced the reading: registration, manual, fuel, maintenance, inspection, wash, correction...
            $table->string('source', 30);
            $table->nullableMorphs('record');
            $table->timestamp('read_at');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->foreign(['tenant_id', 'vehicle_id'])->references(['tenant_id', 'id'])->on('fleet_vehicles')->cascadeOnDelete();
            $table->index(['vehicle_id', 'read_at']);
        });

        Schema::create('fleet_vehicle_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('vehicle_id');
            $table->string('type', 20);
            $table->string('number', 60)->nullable();
            $table->date('expires_on')->nullable();
            $table->string('file_path')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign(['tenant_id', 'vehicle_id'])->references(['tenant_id', 'id'])->on('fleet_vehicles')->cascadeOnDelete();
            $table->index(['tenant_id', 'expires_on']);
        });

        // Important changes of a vehicle (identity, deactivation, odometer corrections): who, when and why.
        Schema::create('fleet_vehicle_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('vehicle_id');
            $table->string('type', 30);
            $table->json('data')->nullable();
            $table->string('reason')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->foreign(['tenant_id', 'vehicle_id'])->references(['tenant_id', 'id'])->on('fleet_vehicles')->cascadeOnDelete();
        });

        Schema::create('fleet_fuel_stations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('document', 30)->nullable();
            $table->string('address')->nullable();
            $table->string('city', 80)->nullable();
            $table->string('phone', 30)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_fuel_stations');
        Schema::dropIfExists('fleet_vehicle_events');
        Schema::dropIfExists('fleet_vehicle_documents');
        Schema::dropIfExists('fleet_odometer_readings');
        Schema::dropIfExists('fleet_driver_vehicle');
        Schema::dropIfExists('fleet_vehicles');
        Schema::dropIfExists('fleet_drivers');
        Schema::dropIfExists('fleet_settings');
    }
};
