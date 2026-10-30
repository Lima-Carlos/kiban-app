<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kiban Campo, part 1: the company's customers and their service addresses ("sites"), each with an optional
 * point on the map. A customer can have many addresses (head office, branches, a house and a shop...).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fd_customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // "company" or "person".
            $table->string('kind', 10)->default('company');
            $table->string('name');
            // Trade name of a company (what people call it).
            $table->string('trade_name')->nullable();
            // CPF/CNPJ in Brazil (digits only); elsewhere any document, with its type.
            $table->string('document_type', 20)->nullable();
            $table->string('document', 40)->nullable();
            // The customer's code in the company's own system (ISP management, ERP...): imports update by it.
            $table->string('external_code', 60)->nullable();
            // Mobile/WhatsApp with the country code (digits), like the people of the company.
            $table->string('phone', 20)->nullable();
            $table->string('phone_2', 20)->nullable();
            $table->string('email')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'external_code']);
            $table->index(['tenant_id', 'document']);
            $table->index(['tenant_id', 'name']);
        });

        Schema::create('fd_sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('customer_id');
            // e.g. "Head office", "Home", "Shop".
            $table->string('label', 80)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('street')->nullable();
            $table->string('number', 20)->nullable();
            $table->string('complement', 120)->nullable();
            $table->string('district', 120)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('state', 60)->nullable();
            $table->string('country', 2)->nullable();
            // How to get there: "blue gate, behind the church".
            $table->text('reference')->nullable();
            $table->string('contact_name', 120)->nullable();
            $table->string('contact_phone', 20)->nullable();
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            // Where the point came from: typed, phone (GPS), spreadsheet, address (search) or technician (first visit).
            $table->string('location_source', 12)->nullable();
            // Address search on the map: pending, found or not_found (null = nothing to search).
            $table->string('locate_status', 10)->nullable();
            $table->timestamp('located_at')->nullable();
            $table->boolean('is_main')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('fd_customers')->cascadeOnDelete();
            $table->index(['tenant_id', 'customer_id']);
            $table->index('locate_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fd_sites');
        Schema::dropIfExists('fd_customers');
    }
};
