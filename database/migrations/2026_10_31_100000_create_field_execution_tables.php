<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kiban Campo, part 4: the work at the customer's place, done on the technician's phone (also without signal):
 * arrival (check-in with the location and the vehicle's km), the answers of the checklist, photos, materials
 * used, what was done, the customer's signature, and pausing or finishing. Each piece sent from the phone has
 * its own code, so sending it twice counts once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fd_orders', function (Blueprint $table) {
            $table->timestamp('checked_in_at')->nullable();
            $table->decimal('check_in_latitude', 9, 6)->nullable();
            $table->decimal('check_in_longitude', 9, 6)->nullable();
            $table->unsignedInteger('check_in_accuracy')->nullable();
            // Meters between the check-in and the customer's point (when both exist).
            $table->unsignedInteger('check_in_distance')->nullable();
            $table->unsignedInteger('check_in_km')->nullable();
            // The fleet vehicle used (Kiban Frota), when there is one.
            $table->unsignedBigInteger('vehicle_id')->nullable();
            $table->text('work_done')->nullable();
            $table->text('pause_reason')->nullable();
            $table->string('signature_path')->nullable();
            $table->string('signed_by', 120)->nullable();
            $table->timestamp('signed_at')->nullable();
        });

        Schema::create('fd_order_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('order_id');
            // The checklist item's fixed key (the label may change; the key never does).
            $table->string('item_key', 40);
            $table->text('value')->nullable();
            $table->timestamp('answered_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'order_id', 'item_key']);
            $table->foreign(['tenant_id', 'order_id'])->references(['tenant_id', 'id'])->on('fd_orders')->cascadeOnDelete();
        });

        Schema::create('fd_order_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('order_id');
            // The checklist item of the photo (null = a free photo of the service).
            $table->string('item_key', 40)->nullable();
            $table->string('path');
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->timestamp('taken_at');
            $table->uuid('client_id');
            $table->timestamps();

            $table->unique(['tenant_id', 'client_id']);
            $table->foreign(['tenant_id', 'order_id'])->references(['tenant_id', 'id'])->on('fd_orders')->cascadeOnDelete();
            $table->index(['tenant_id', 'order_id']);
        });

        Schema::create('fd_order_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('material_id')->nullable();
            $table->string('description');
            $table->string('unit', 10)->default('un');
            $table->decimal('quantity', 10, 2);
            // Price of the catalog when it was used (the report and the totals keep it).
            $table->unsignedBigInteger('unit_price_cents')->nullable();
            $table->uuid('client_id');
            $table->timestamps();

            $table->unique(['tenant_id', 'client_id']);
            $table->foreign(['tenant_id', 'order_id'])->references(['tenant_id', 'id'])->on('fd_orders')->cascadeOnDelete();
            $table->index(['tenant_id', 'order_id']);
        });

        // Every piece received from the phones (so a piece sent twice counts once).
        Schema::create('fd_order_sync', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('order_id');
            $table->uuid('client_id');
            $table->string('type', 20);
            $table->timestamp('taken_at');
            $table->timestamp('created_at')->nullable();

            $table->unique(['tenant_id', 'client_id']);
            $table->foreign(['tenant_id', 'order_id'])->references(['tenant_id', 'id'])->on('fd_orders')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fd_order_sync');
        Schema::dropIfExists('fd_order_materials');
        Schema::dropIfExists('fd_order_photos');
        Schema::dropIfExists('fd_order_answers');
        Schema::table('fd_orders', function (Blueprint $table) {
            $table->dropColumn(['checked_in_at', 'check_in_latitude', 'check_in_longitude', 'check_in_accuracy', 'check_in_distance', 'check_in_km', 'vehicle_id', 'work_done', 'pause_reason', 'signature_path', 'signed_by', 'signed_at']);
        });
    }
};
