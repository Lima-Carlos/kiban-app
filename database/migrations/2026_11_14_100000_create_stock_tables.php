<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kiban Core Estoque (inventory): items, places (warehouse and each technician's vehicle), the balance of each
     * item in each place, every movement, and the units tracked by serial number (equipment lent to customers).
     */
    public function up(): void
    {
        Schema::create('es_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            // "warehouse" or "technician" (the stock a technician carries).
            $table->string('kind', 12)->default('warehouse');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
        });

        Schema::create('es_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('sku', 60)->nullable();
            $table->string('unit', 10)->default('un');
            $table->decimal('min_quantity', 14, 3)->default(0);
            // Tracked one by one by serial number (e.g. routers lent to customers).
            $table->boolean('serialized')->default(false);
            // The same material of the Campo: what the technician writes down on an order leaves their stock.
            $table->unsignedBigInteger('material_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'material_id'])->references(['tenant_id', 'id'])->on('fd_materials')->noActionOnDelete();
        });

        Schema::create('es_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('location_id');
            $table->decimal('quantity', 14, 3)->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['item_id', 'location_id']);
            $table->foreign(['tenant_id', 'item_id'])->references(['tenant_id', 'id'])->on('es_items')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'location_id'])->references(['tenant_id', 'id'])->on('es_locations')->cascadeOnDelete();
        });

        Schema::create('es_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('item_id');
            $table->string('serial', 80);
            $table->string('mac', 40)->nullable();
            // stock, customer, defective, lost
            $table->string('status', 12)->default('stock');
            $table->unsignedBigInteger('location_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('customer_name', 160)->nullable();
            $table->timestamp('since_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'item_id', 'serial']);
            $table->foreign(['tenant_id', 'item_id'])->references(['tenant_id', 'id'])->on('es_items')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'location_id'])->references(['tenant_id', 'id'])->on('es_locations')->noActionOnDelete();
            $table->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('fd_customers')->noActionOnDelete();
        });

        Schema::create('es_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('item_id');
            // entry, transfer, use, adjust, lend, return, loss
            $table->string('kind', 10);
            $table->decimal('quantity', 14, 3);
            $table->unsignedBigInteger('from_location_id')->nullable();
            $table->unsignedBigInteger('to_location_id')->nullable();
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->unsignedBigInteger('work_order_id')->nullable();
            $table->unsignedBigInteger('order_material_id')->nullable();
            $table->string('customer_name', 160)->nullable();
            $table->string('note', 255)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moved_at');
            $table->timestamps();

            $table->foreign(['tenant_id', 'item_id'])->references(['tenant_id', 'id'])->on('es_items')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'from_location_id'])->references(['tenant_id', 'id'])->on('es_locations')->noActionOnDelete();
            $table->foreign(['tenant_id', 'to_location_id'])->references(['tenant_id', 'id'])->on('es_locations')->noActionOnDelete();
            $table->foreign(['tenant_id', 'unit_id'])->references(['tenant_id', 'id'])->on('es_units')->noActionOnDelete();
            $table->index(['tenant_id', 'moved_at']);
            $table->index(['tenant_id', 'order_material_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('es_movements');
        Schema::dropIfExists('es_units');
        Schema::dropIfExists('es_balances');
        Schema::dropIfExists('es_items');
        Schema::dropIfExists('es_locations');
    }
};
