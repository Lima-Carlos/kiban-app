<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kiban Campo, part 3: the work orders (OS) with their own number in the company, the customer and the address,
 * the type of service (its checklist copied when the order is opened), the technician and the time on the agenda,
 * and the history of everything that happened to each order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fd_settings', function (Blueprint $table) {
            // Last work order number given in the company.
            $table->unsignedBigInteger('last_order_number')->default(0);
        });

        Schema::create('fd_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('number');
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('site_id')->nullable();
            $table->unsignedBigInteger('service_type_id');
            // open, scheduled, in_progress, paused, done, canceled.
            $table->string('status', 12)->default('open');
            // normal, high, urgent.
            $table->string('priority', 8)->default('normal');
            // What the customer asked or reported.
            $table->text('reported')->nullable();
            // Only for the company (the customer never sees it).
            $table->text('internal_notes')->nullable();
            $table->foreignId('technician_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('scheduled_at')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->default(60);
            $table->unsignedBigInteger('price_cents')->nullable();
            // Copy of the type's checklist when the order was opened (the answers come in part 4).
            $table->json('checklist');
            $table->boolean('signature_required')->default(true);
            $table->boolean('photo_required')->default(false);
            // manual or quote (part 2b).
            $table->string('origin', 10)->default('manual');
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'number']);
            $table->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('fd_customers')->restrictOnDelete();
            $table->foreign(['tenant_id', 'site_id'])->references(['tenant_id', 'id'])->on('fd_sites')->restrictOnDelete();
            $table->foreign(['tenant_id', 'service_type_id'])->references(['tenant_id', 'id'])->on('fd_service_types')->restrictOnDelete();
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'scheduled_at']);
            $table->index(['tenant_id', 'technician_id', 'scheduled_at']);
            $table->index(['tenant_id', 'customer_id']);
        });

        Schema::create('fd_order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('order_id');
            // opened, scheduled, unscheduled, status, canceled, note.
            $table->string('type', 20);
            $table->json('data')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->foreign(['tenant_id', 'order_id'])->references(['tenant_id', 'id'])->on('fd_orders')->cascadeOnDelete();
            $table->index(['tenant_id', 'order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fd_order_events');
        Schema::dropIfExists('fd_orders');
        Schema::table('fd_settings', function (Blueprint $table) {
            $table->dropColumn('last_order_number');
        });
    }
};
