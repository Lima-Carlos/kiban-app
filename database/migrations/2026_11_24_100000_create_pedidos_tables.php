<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kiban Core Pedidos (orders by phone and WhatsApp for small food businesses: pizza, burger, sushi, lunch boxes...).
 * The customers are the company's customers (fd_customers and their addresses fd_sites, the same register as the
 * Campo and the Agenda); this module adds the menu, the orders, the delivery runs and, per customer, the payment
 * preference and the WhatsApp consent. Every link between company tables uses (tenant_id, id), so the database never
 * mixes companies. Links to people are cleared (never deleted) when a person record is erased.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pd_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // Where the deliveries leave from (the shop): text and point on the map.
            $table->string('address', 255)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            // City and state filled in on new customers' addresses (most deliveries are in the shop's city).
            $table->string('city', 120)->nullable();
            $table->string('state', 60)->nullable();
            // Delivery fee suggested on a new order (cents; the order may change it).
            $table->unsignedInteger('delivery_fee_cents')->default(0);
            // The ready WhatsApp message for customers who stopped ordering (editable).
            $table->text('promo_message')->nullable();
            // Rhythm: late after this percent of the customer's usual interval; stopped after this one.
            $table->unsignedSmallInteger('late_percent')->default(150);
            $table->unsignedSmallInteger('stopped_percent')->default(300);
            // Orders needed before the customer has a rhythm.
            $table->unsignedSmallInteger('min_orders')->default(3);
            $table->timestamp('configured_at')->nullable();
            $table->timestamps();

            $table->unique('tenant_id');
        });

        Schema::create('pd_menu_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('category', 60)->nullable();
            $table->unsignedInteger('price_cents')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'is_active', 'category']);
        });

        // What the Pedidos keeps about a customer of the shared register.
        Schema::create('pd_client_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('customer_id');
            // cash, pix, card, app (paid in an app).
            $table->string('payment_preference', 12)->nullable();
            // The customer agreed to receive offers by WhatsApp (LGPD): only then the "come back" message is offered.
            $table->boolean('whatsapp_consent')->default(false);
            $table->timestamp('whatsapp_consent_at')->nullable();
            // Last "come back" message opened for this customer.
            $table->timestamp('last_promo_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'customer_id']);
            $table->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('fd_customers')->cascadeOnDelete();
        });

        // Who works in the kitchen and who delivers (people of the "People" screen, with or without a login).
        Schema::create('pd_staff', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('person_id');
            $table->boolean('cooks')->default(false);
            $table->boolean('delivers')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'person_id']);
            $table->foreign(['tenant_id', 'person_id'])->references(['tenant_id', 'id'])->on('people')->cascadeOnDelete();
        });

        Schema::create('pd_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('driver_id')->nullable();
            // open (on the street) or done.
            $table->string('status', 8)->default('open');
            // Straight-line distance of the suggested way, shop → last stop (meters).
            $table->unsignedInteger('distance_m')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('pd_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            // Empty for a quick counter sale without a name.
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('site_id')->nullable();
            // The delivery address as it was when ordered (the customer may move later).
            $table->string('address', 255)->nullable();
            // delivery or pickup.
            $table->string('fulfillment', 8)->default('delivery');
            // phone, whatsapp, counter, app (iFood and other apps: history only).
            $table->string('source', 10)->default('phone');
            $table->string('payment_method', 8)->nullable();
            // Cash: change for this note.
            $table->unsignedInteger('change_for_cents')->nullable();
            // new → preparing → ready → out → delivered (or cancelled).
            $table->string('status', 10)->default('new');
            $table->text('notes')->nullable();
            $table->unsignedInteger('subtotal_cents')->default(0);
            $table->unsignedInteger('delivery_fee_cents')->default(0);
            $table->unsignedInteger('total_cents')->default(0);
            $table->unsignedBigInteger('prepared_by')->nullable();
            $table->unsignedBigInteger('delivered_by')->nullable();
            $table->unsignedBigInteger('run_id')->nullable();
            $table->unsignedSmallInteger('run_position')->nullable();
            $table->timestamp('preparing_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('out_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'customer_id', 'created_at']);
            $table->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('fd_customers');
            $table->foreign(['tenant_id', 'run_id'])->references(['tenant_id', 'id'])->on('pd_runs');
        });

        Schema::create('pd_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('menu_item_id')->nullable();
            // Name and price as they were when ordered.
            $table->string('name', 120);
            $table->unsignedInteger('unit_price_cents')->default(0);
            $table->unsignedSmallInteger('quantity')->default(1);
            // Free text of the line: "sem cebola", "meio a meio calabresa".
            $table->string('notes', 255)->nullable();
            $table->unsignedInteger('total_cents')->default(0);
            $table->timestamps();

            $table->foreign(['tenant_id', 'order_id'])->references(['tenant_id', 'id'])->on('pd_orders')->cascadeOnDelete();
            $table->index(['tenant_id', 'order_id']);
        });

        // Links that may be cleared: only the column becomes empty, never the company (PostgreSQL 15+).
        DB::statement('ALTER TABLE pd_orders ADD CONSTRAINT pd_orders_site_fk FOREIGN KEY (tenant_id, site_id) REFERENCES fd_sites (tenant_id, id) ON DELETE SET NULL (site_id)');
        DB::statement('ALTER TABLE pd_orders ADD CONSTRAINT pd_orders_prepared_fk FOREIGN KEY (tenant_id, prepared_by) REFERENCES people (tenant_id, id) ON DELETE SET NULL (prepared_by)');
        DB::statement('ALTER TABLE pd_orders ADD CONSTRAINT pd_orders_delivered_fk FOREIGN KEY (tenant_id, delivered_by) REFERENCES people (tenant_id, id) ON DELETE SET NULL (delivered_by)');
        DB::statement('ALTER TABLE pd_runs ADD CONSTRAINT pd_runs_driver_fk FOREIGN KEY (tenant_id, driver_id) REFERENCES people (tenant_id, id) ON DELETE SET NULL (driver_id)');
        DB::statement('ALTER TABLE pd_order_lines ADD CONSTRAINT pd_order_lines_item_fk FOREIGN KEY (tenant_id, menu_item_id) REFERENCES pd_menu_items (tenant_id, id) ON DELETE SET NULL (menu_item_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('pd_order_lines');
        Schema::dropIfExists('pd_orders');
        Schema::dropIfExists('pd_runs');
        Schema::dropIfExists('pd_staff');
        Schema::dropIfExists('pd_client_details');
        Schema::dropIfExists('pd_menu_items');
        Schema::dropIfExists('pd_settings');
    }
};
