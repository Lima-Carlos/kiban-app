<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kiban Ponto, part 3: the punches (never changed), the company tablets used as time clocks and the
     * personal code (PIN) of each employee for the tablet.
     */
    public function up(): void
    {
        // A tablet of the company in "time clock mode" (no login): the employee types the PIN and takes a photo.
        Schema::create('tc_kiosks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedBigInteger('workplace_id');
            // Hash of the secret kept on the tablet (the secret itself is never stored).
            $table->string('token_hash', 64)->nullable()->unique();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'workplace_id'])->references(['tenant_id', 'id'])->on('tc_workplaces')->noActionOnDelete();
        });

        Schema::create('tc_punches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('employee_id');
            // Sequential number of the punch in the company (NSR, used in the official files in Brazil).
            $table->unsignedBigInteger('nsr');
            // Server time (UTC); never the phone's clock.
            $table->timestamp('punched_at');
            // Time zone of the workplace at that moment (the receipt and the time sheet show local time).
            $table->string('timezone', 64);
            // "phone" or "kiosk".
            $table->string('source', 10);
            $table->unsignedBigInteger('kiosk_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->unsignedInteger('accuracy_meters')->nullable();
            $table->unsignedInteger('distance_meters')->nullable();
            // Warnings for the manager (never a block): outside_radius, no_location, no_photo.
            $table->json('flags');
            $table->string('photo_path')->nullable();
            $table->string('device')->nullable();
            // Chain of hashes: each punch carries the hash of the previous one (any change would show).
            $table->string('hash', 64);
            $table->timestamp('created_at')->nullable();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'nsr']);
            $table->foreign(['tenant_id', 'employee_id'])->references(['tenant_id', 'id'])->on('tc_employees')->noActionOnDelete();
            $table->foreign(['tenant_id', 'kiosk_id'])->references(['tenant_id', 'id'])->on('tc_kiosks')->noActionOnDelete();
            $table->index(['tenant_id', 'employee_id', 'punched_at']);
        });

        Schema::table('tc_employees', function (Blueprint $table) {
            // Personal code for the tablet: stored as a keyed hash (it can be found, never read back).
            $table->string('pin_lookup', 64)->nullable();
            $table->timestamp('pin_set_at')->nullable();
            $table->unique(['tenant_id', 'pin_lookup']);
        });

        // The last number and hash of the company's punches (taken under a lock, so two punches never share a number).
        Schema::table('tc_settings', function (Blueprint $table) {
            $table->unsignedBigInteger('last_nsr')->default(0);
            $table->string('last_punch_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tc_settings', function (Blueprint $table) {
            $table->dropColumn(['last_nsr', 'last_punch_hash']);
        });
        Schema::table('tc_employees', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'pin_lookup']);
            $table->dropColumn(['pin_lookup', 'pin_set_at']);
        });
        Schema::dropIfExists('tc_punches');
        Schema::dropIfExists('tc_kiosks');
    }
};
