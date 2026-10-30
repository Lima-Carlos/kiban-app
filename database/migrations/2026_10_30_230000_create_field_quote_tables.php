<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kiban Campo, part 2b: quotes with services and materials, sent to the customer by a link (WhatsApp); the
 * customer approves or declines on the phone, and the approved quote becomes a work order by itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fd_settings', function (Blueprint $table) {
            $table->unsignedBigInteger('last_quote_number')->default(0);
            // Days a quote stays valid by default.
            $table->unsignedSmallInteger('quote_valid_days')->default(15);
        });

        Schema::create('fd_quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('number');
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('site_id')->nullable();
            // draft, sent, approved, declined.
            $table->string('status', 10)->default('draft');
            $table->date('valid_until');
            // Shown to the customer (conditions, deadline, payment).
            $table->text('notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->unsignedBigInteger('discount_cents')->default(0);
            $table->unsignedBigInteger('total_cents')->default(0);
            // The customer's link (long and random: it cannot be guessed).
            $table->string('token', 64)->unique();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            // Who approved or declined on the link, with where from (proof of the agreement).
            $table->string('answered_by', 120)->nullable();
            $table->string('answered_ip', 45)->nullable();
            $table->string('answered_device')->nullable();
            $table->text('decline_reason')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'number']);
            $table->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('fd_customers')->restrictOnDelete();
            $table->foreign(['tenant_id', 'site_id'])->references(['tenant_id', 'id'])->on('fd_sites')->nullOnDelete();
            $table->foreign(['tenant_id', 'order_id'])->references(['tenant_id', 'id'])->on('fd_orders')->nullOnDelete();
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('fd_quote_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('quote_id');
            $table->unsignedBigInteger('service_type_id')->nullable();
            $table->unsignedBigInteger('material_id')->nullable();
            $table->string('description');
            $table->decimal('quantity', 10, 2)->default(1);
            $table->string('unit', 10)->default('un');
            $table->unsignedBigInteger('unit_price_cents')->default(0);
            $table->unsignedBigInteger('total_cents')->default(0);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->foreign(['tenant_id', 'quote_id'])->references(['tenant_id', 'id'])->on('fd_quotes')->cascadeOnDelete();
            $table->index(['tenant_id', 'quote_id']);
        });

        Schema::table('fd_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('quote_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('fd_orders', function (Blueprint $table) {
            $table->dropColumn('quote_id');
        });
        Schema::dropIfExists('fd_quote_items');
        Schema::dropIfExists('fd_quotes');
        Schema::table('fd_settings', function (Blueprint $table) {
            $table->dropColumn(['last_quote_number', 'quote_valid_days']);
        });
    }
};
