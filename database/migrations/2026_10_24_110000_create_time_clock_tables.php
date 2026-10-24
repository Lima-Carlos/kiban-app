<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kiban Ponto, part 1: the company's time clock settings (country and labor rule profile), workplaces
     * (optional radius on the map) and employees (linked to the person of the "People" screen).
     */
    public function up(): void
    {
        Schema::create('tc_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->char('country', 2);
            // "br_clt" (Brazil, complete) or "custom" (other countries: the company states its rules).
            $table->string('labor_profile', 20);
            // The manager confirmed the country and the rules (first step).
            $table->timestamp('configured_at')->nullable();
            $table->timestamps();
        });

        Schema::create('tc_workplaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('city', 80)->nullable();
            $table->string('state', 40)->nullable();
            // Punches follow the time zone of the place.
            $table->string('timezone', 64);
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            // Optional: outside this radius a punch is flagged for the manager (never blocked).
            $table->unsignedInteger('radius_meters')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
        });

        Schema::create('tc_employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('person_id');
            $table->string('registration', 30)->nullable();
            // Brazil only (PIS/PASEP/NIT, required in the official files).
            $table->string('pis', 11)->nullable();
            $table->date('admitted_on');
            $table->date('terminated_on')->nullable();
            $table->string('job_title', 120)->nullable();
            $table->unsignedBigInteger('workplace_id')->nullable();
            // Works in the street (e.g. a technician): no workplace radius, only the location is recorded.
            $table->boolean('is_external')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'person_id']);
            $table->foreign(['tenant_id', 'person_id'])->references(['tenant_id', 'id'])->on('people')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'workplace_id'])->references(['tenant_id', 'id'])->on('tc_workplaces')->noActionOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tc_employees');
        Schema::dropIfExists('tc_workplaces');
        Schema::dropIfExists('tc_settings');
    }
};
