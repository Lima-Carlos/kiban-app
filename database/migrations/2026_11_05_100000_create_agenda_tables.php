<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kiban Core Agenda (online appointments; first sector: pet shops). The clients are the company's customers
 * (fd_customers, the same register as the Campo: one company, one list of customers); each client has the
 * attended items (a pet now; a car later, for car detailing). Professionals are people of the "People" screen.
 * Every link between company tables uses (tenant_id, id), so the database never mixes companies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ag_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // Ready model of services: "petshop" or "none".
            $table->string('business', 20)->nullable();
            $table->string('currency', 3)->default('BRL');
            // The public booking page (company address + /agendar) takes bookings.
            $table->boolean('online_booking')->default(true);
            // Step between the times offered (minutes).
            $table->unsignedSmallInteger('slot_minutes')->default(30);
            // Online: the earliest booking is this many minutes from now.
            $table->unsignedSmallInteger('min_notice_minutes')->default(120);
            // Online: how many days ahead.
            $table->unsignedSmallInteger('booking_days')->default(30);
            // The client may cancel or reschedule by the link until this many hours before.
            $table->unsignedSmallInteger('cancel_hours')->default(4);
            // From this many no-shows the client is flagged.
            $table->unsignedSmallInteger('no_show_flag')->default(2);
            // Pick-up and drop-off offered on the booking page.
            $table->boolean('pickup_enabled')->default(false);
            // Campo bridge: the type of service of the work order opened for a pick-up.
            $table->unsignedBigInteger('field_service_type_id')->nullable();
            // Shown at the top of the booking page.
            $table->text('booking_message')->nullable();
            $table->timestamp('configured_at')->nullable();
            $table->timestamps();

            $table->unique('tenant_id');
        });

        Schema::create('ag_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('customer_id');
            // What is attended: "pet" (later "vehicle" for car detailing).
            $table->string('kind', 12)->default('pet');
            $table->string('name', 120);
            // Pets: dog, cat, other.
            $table->string('species', 12)->nullable();
            $table->string('breed', 120)->nullable();
            // Size for prices and times: small, medium, large, xlarge (P/M/G/GG).
            $table->string('size', 8)->nullable();
            // Coat: short, medium, long, curly, double.
            $table->string('coat', 12)->nullable();
            $table->date('birth_date')->nullable();
            // Allergies, behavior ("bites on the paws"), what the professional must know.
            $table->text('notes')->nullable();
            $table->string('photo_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('fd_customers')->cascadeOnDelete();
            $table->index(['tenant_id', 'customer_id']);
        });

        Schema::create('ag_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            // Fixed code of a ready model service (e.g. "bath"), null for the company's own ones.
            $table->string('template_key', 40)->nullable();
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->default(60);
            $table->unsignedInteger('price_cents')->nullable();
            // Per size: {"small": {"price_cents": 5000, "duration_minutes": 45}, ...}; empty = the base values.
            $table->json('size_prices')->nullable();
            // Add-on: only together with a main service (e.g. hydration, nail cutting).
            $table->boolean('is_addon')->default(false);
            // Pick-up and drop-off ("leva e traz").
            $table->boolean('is_pickup')->default(false);
            // Offered on the public booking page.
            $table->boolean('online')->default(true);
            $table->string('color', 7)->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'template_key']);
        });

        Schema::create('ag_professionals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('person_id');
            $table->string('color', 7)->nullable();
            // Weekly hours like the Ponto schedules: {"1": {"works": true, "start": "08:00", "end": "18:00", "break_start": "12:00", "break_end": "13:00"}, ...}.
            $table->json('week')->nullable();
            // With the Ponto: the hours come from the person's work schedule (when there is one).
            $table->boolean('follows_time_clock')->default(true);
            // Default commission (percent, two decimals in hundredths: 3000 = 30%).
            $table->unsignedSmallInteger('commission_bp')->default(0);
            // Appears on the public booking page.
            $table->boolean('online')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'person_id']);
            $table->foreign(['tenant_id', 'person_id'])->references(['tenant_id', 'id'])->on('people')->cascadeOnDelete();
        });

        // Which services each professional does (none = all of them) and the commission of each one.
        Schema::create('ag_professional_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('professional_id');
            $table->unsignedBigInteger('service_id');
            // Null = the professional's default commission.
            $table->unsignedSmallInteger('commission_bp')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'professional_id', 'service_id']);
            $table->foreign(['tenant_id', 'professional_id'])->references(['tenant_id', 'id'])->on('ag_professionals')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'service_id'])->references(['tenant_id', 'id'])->on('ag_services')->cascadeOnDelete();
        });

        // Days off, vacations, a doctor's appointment: no booking in this time.
        Schema::create('ag_time_off', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('professional_id');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('reason', 160)->nullable();
            $table->timestamps();

            $table->foreign(['tenant_id', 'professional_id'])->references(['tenant_id', 'id'])->on('ag_professionals')->cascadeOnDelete();
            $table->index(['tenant_id', 'professional_id', 'starts_at']);
        });

        // Packages for sale (e.g. 4 baths in 60 days).
        Schema::create('ag_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->unsignedBigInteger('service_id');
            $table->unsignedSmallInteger('quantity');
            $table->unsignedSmallInteger('valid_days')->default(60);
            $table->unsignedInteger('price_cents')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'service_id'])->references(['tenant_id', 'id'])->on('ag_services')->noActionOnDelete();
        });

        // A package sold to a client (optionally for one pet): the copy keeps the numbers of the sale.
        Schema::create('ag_client_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('item_id')->nullable();
            $table->unsignedBigInteger('package_id')->nullable();
            $table->unsignedBigInteger('service_id');
            $table->string('name', 120);
            $table->unsignedSmallInteger('quantity');
            $table->unsignedInteger('price_cents')->nullable();
            $table->date('sold_on');
            $table->date('expires_on');
            $table->foreignId('sold_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('fd_customers')->noActionOnDelete();
            $table->foreign(['tenant_id', 'item_id'])->references(['tenant_id', 'id'])->on('ag_items')->noActionOnDelete();
            $table->foreign(['tenant_id', 'package_id'])->references(['tenant_id', 'id'])->on('ag_packages')->noActionOnDelete();
            $table->foreign(['tenant_id', 'service_id'])->references(['tenant_id', 'id'])->on('ag_services')->noActionOnDelete();
            $table->index(['tenant_id', 'expires_on']);
        });

        Schema::create('ag_appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('item_id')->nullable();
            $table->unsignedBigInteger('professional_id');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            // scheduled, confirmed, arrived, in_service, ready, delivered, no_show, canceled.
            $table->string('status', 12)->default('scheduled');
            // desk or online.
            $table->string('origin', 8)->default('desk');
            $table->string('size', 8)->nullable();
            $table->unsignedInteger('price_cents')->default(0);
            $table->text('notes')->nullable();
            // The client's link (confirm, reschedule, cancel): long and random.
            $table->string('token', 48);
            // Pick-up ("leva e traz"): the address (a customer address) and the window to pick the pet up.
            $table->boolean('pickup')->default(false);
            $table->boolean('dropoff')->default(false);
            $table->unsignedBigInteger('pickup_site_id')->nullable();
            $table->string('pickup_from', 5)->nullable();
            $table->string('pickup_until', 5)->nullable();
            // Campo bridge: the work order opened for the pick-up.
            $table->unsignedBigInteger('field_order_id')->nullable();
            // A package used by this appointment.
            $table->unsignedBigInteger('client_package_id')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('no_show_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            // company or client.
            $table->string('canceled_by', 8)->nullable();
            $table->string('cancel_reason', 255)->nullable();
            // "Remind tomorrow" and "pet ready" sent by WhatsApp (by the desk).
            $table->timestamp('reminded_at')->nullable();
            $table->timestamp('ready_sent_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique('token');
            $table->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('fd_customers')->noActionOnDelete();
            $table->foreign(['tenant_id', 'item_id'])->references(['tenant_id', 'id'])->on('ag_items')->noActionOnDelete();
            $table->foreign(['tenant_id', 'professional_id'])->references(['tenant_id', 'id'])->on('ag_professionals')->noActionOnDelete();
            $table->foreign(['tenant_id', 'pickup_site_id'])->references(['tenant_id', 'id'])->on('fd_sites')->noActionOnDelete();
            $table->foreign(['tenant_id', 'field_order_id'])->references(['tenant_id', 'id'])->on('fd_orders')->noActionOnDelete();
            $table->foreign(['tenant_id', 'client_package_id'])->references(['tenant_id', 'id'])->on('ag_client_packages')->noActionOnDelete();
            $table->index(['tenant_id', 'professional_id', 'starts_at']);
            $table->index(['tenant_id', 'starts_at']);
            $table->index(['tenant_id', 'customer_id']);
        });

        // The services of an appointment (main + add-ons), with the price, time and commission of that moment.
        Schema::create('ag_appointment_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('appointment_id');
            $table->unsignedBigInteger('service_id')->nullable();
            $table->string('name', 120);
            $table->unsignedSmallInteger('duration_minutes');
            $table->unsignedInteger('price_cents')->default(0);
            $table->unsignedSmallInteger('commission_bp')->default(0);
            $table->timestamps();

            $table->foreign(['tenant_id', 'appointment_id'])->references(['tenant_id', 'id'])->on('ag_appointments')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'service_id'])->references(['tenant_id', 'id'])->on('ag_services')->noActionOnDelete();
        });

        // History of each appointment (who did what and when).
        Schema::create('ag_appointment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('appointment_id');
            $table->string('type', 30);
            $table->json('data')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->foreign(['tenant_id', 'appointment_id'])->references(['tenant_id', 'id'])->on('ag_appointments')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ag_appointment_events');
        Schema::dropIfExists('ag_appointment_lines');
        Schema::dropIfExists('ag_appointments');
        Schema::dropIfExists('ag_client_packages');
        Schema::dropIfExists('ag_packages');
        Schema::dropIfExists('ag_time_off');
        Schema::dropIfExists('ag_professional_services');
        Schema::dropIfExists('ag_professionals');
        Schema::dropIfExists('ag_services');
        Schema::dropIfExists('ag_items');
        Schema::dropIfExists('ag_settings');
    }
};
