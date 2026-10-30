<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kiban Campo, part 2: the company's settings (its business and currency), the types of service with the
 * technician's checklist, and the catalog of materials (used on the work orders and on the quotes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fd_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            // isp, hvac, security, general or none (chosen in the first steps; the ready model comes from it).
            $table->string('business', 20)->nullable();
            $table->string('currency', 3)->default('BRL');
            // The customer signs on the phone at the end of the work (default of new types of service).
            $table->boolean('signature_required')->default(true);
            $table->timestamp('configured_at')->nullable();
            $table->timestamps();
        });

        Schema::create('fd_service_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('color', 7)->nullable();
            // Expected time on the customer's place (the agenda uses it).
            $table->unsignedSmallInteger('duration_minutes')->default(60);
            // Price of the service (cents), suggested on the quotes. Optional.
            $table->unsignedBigInteger('price_cents')->nullable();
            // What the technician fills in: [{key, label, type, required, unit, options}].
            $table->json('checklist');
            $table->boolean('signature_required')->default(true);
            $table->boolean('photo_required')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('fd_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // The code in the company's own stock or system.
            $table->string('code', 60)->nullable();
            // un, m, kg, l, h...
            $table->string('unit', 10)->default('un');
            $table->unsignedBigInteger('price_cents')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fd_materials');
        Schema::dropIfExists('fd_service_types');
        Schema::dropIfExists('fd_settings');
    }
};
