<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Documents with a validity of each person of the company (driver's license, medical exam, safety
     * trainings...): the company's document types (ready ones per country + its own) and the documents.
     * Shared by every system (People screen), not a module.
     */
    public function up(): void
    {
        Schema::create('person_document_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // Ready type (translated name, e.g. "cnh", "aso", "nr10"); null for the company's own types.
            $table->string('key', 40)->nullable();
            // Name typed by the company (own types, or a ready one renamed).
            $table->string('name', 80)->nullable();
            // Suggested validity: the expiry is filled from the issue date. Null = the date is typed.
            $table->unsignedSmallInteger('validity_months')->nullable();
            $table->unsignedSmallInteger('alert_days')->default(30);
            // Health data (LGPD, art. 11): only owners, administrators and HR see it.
            $table->boolean('is_health')->default(false);
            // System it matters to (e.g. "frota" for the driver's license, "campo" for NR-10).
            $table->string('module', 30)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'key']);
        });

        Schema::create('person_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('person_id');
            $table->unsignedBigInteger('document_type_id');
            $table->string('number', 60)->nullable();
            $table->date('issued_on')->nullable();
            // Null = does not expire.
            $table->date('expires_on')->nullable();
            // Private disk, the company's folder; never public.
            $table->string('file_path')->nullable();
            $table->text('notes')->nullable();
            // Renewed: a newer document of the same type took its place (it stays as history).
            $table->timestamp('replaced_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'replaced_at', 'expires_on']);
            // Deleting a person goes through the model (the files are removed with the records).
            $table->foreign(['tenant_id', 'person_id'])->references(['tenant_id', 'id'])->on('people')->noActionOnDelete();
            $table->foreign(['tenant_id', 'document_type_id'])->references(['tenant_id', 'id'])->on('person_document_types')->noActionOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_documents');
        Schema::dropIfExists('person_document_types');
    }
};
