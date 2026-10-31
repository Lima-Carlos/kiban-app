<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "PPE and tools" of the people (People screen, every system; not a module): the company's catalog (PPE with the
 * approval certificate "CA" and tools), simple stock, deliveries to a person signed with the finger on the phone
 * (NR-6 record), returns and the company's acknowledgment text. A signed delivery never changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('epi_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // Ready item (translated name, e.g. "helmet"); null for the company's own items.
            $table->string('key', 40)->nullable();
            $table->string('name', 120)->nullable();
            // "epi" (personal protective equipment) or "tool" (tool, equipment, uniform).
            $table->string('kind', 10);
            $table->string('size', 20)->nullable();
            $table->string('code', 40)->nullable();
            // Brazil: number and validity of the approval certificate (CA). Elsewhere: an optional certification.
            $table->string('ca_number', 40)->nullable();
            $table->date('ca_expires_on')->nullable();
            // Suggested useful life after the delivery (e.g. 30 days, 24 months). Null = no automatic replacement.
            $table->unsignedSmallInteger('life_amount')->nullable();
            $table->string('life_unit', 10)->default('months');
            $table->unsignedInteger('unit_cost_cents')->nullable();
            // Null = the stock is not controlled. It may go below zero (the delivery is never blocked; a warning shows).
            $table->integer('stock')->nullable();
            $table->unsignedInteger('min_stock')->nullable();
            // The bell already warned about the low stock (cleared when the stock goes back above the minimum).
            $table->timestamp('low_stock_notified_at')->nullable();
            // Goes back to the stock when returned (tools); PPE is usually discarded.
            $table->boolean('reusable')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'key']);
        });

        Schema::create('epi_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('person_id');
            $table->date('delivered_on');
            $table->string('reason', 20);
            $table->text('notes')->nullable();
            // The signature (frozen): image on the private disk, who signed, when, from where and on which device,
            // the acknowledgment text exactly as shown, and a hash of everything.
            $table->string('signature_path')->nullable();
            $table->string('signer_name', 120)->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->string('signed_ip', 45)->nullable();
            $table->string('signed_user_agent', 500)->nullable();
            $table->foreignId('signed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('signed_on_own_device')->default(false);
            $table->text('terms')->nullable();
            $table->string('signature_hash', 64)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'signed_at']);
            $table->foreign(['tenant_id', 'person_id'])->references(['tenant_id', 'id'])->on('people')->noActionOnDelete();
        });

        Schema::create('epi_delivery_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('delivery_id');
            $table->unsignedBigInteger('item_id');
            $table->unsignedInteger('quantity');
            // How many came back (returned, discarded or lost); the rest is with the person.
            $table->unsignedInteger('returned_quantity')->default(0);
            // What the item was at the delivery (the record keeps it even if the catalog changes).
            $table->string('item_name', 120);
            $table->string('kind', 10);
            $table->string('size', 20)->nullable();
            $table->string('ca_number', 40)->nullable();
            $table->date('ca_expires_on')->nullable();
            $table->unsignedInteger('unit_cost_cents')->nullable();
            // Replacement by the useful life (null = none).
            $table->date('replace_on')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'replace_on']);
            $table->foreign(['tenant_id', 'delivery_id'])->references(['tenant_id', 'id'])->on('epi_deliveries')->noActionOnDelete();
            $table->foreign(['tenant_id', 'item_id'])->references(['tenant_id', 'id'])->on('epi_items')->noActionOnDelete();
        });

        Schema::create('epi_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('line_id');
            $table->unsignedInteger('quantity');
            $table->date('returned_on');
            // "stock" (back to the stock), "discard" (written off) or "lost".
            $table->string('outcome', 10);
            $table->string('reason', 20);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'line_id'])->references(['tenant_id', 'id'])->on('epi_delivery_lines')->noActionOnDelete();
        });

        // The acknowledgment text of the company (null = the standard one, in the language of who signs).
        Schema::create('epi_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('terms')->nullable();
            $table->timestamps();
        });

        // Campo: the PPE a type of service needs (e.g. height work → harness); only a warning, never a block.
        Schema::table('fd_service_types', function (Blueprint $table) {
            $table->json('required_epi')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('fd_service_types', function (Blueprint $table) {
            $table->dropColumn('required_epi');
        });
        Schema::dropIfExists('epi_settings');
        Schema::dropIfExists('epi_returns');
        Schema::dropIfExists('epi_delivery_lines');
        Schema::dropIfExists('epi_deliveries');
        Schema::dropIfExists('epi_items');
    }
};
