<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kiban Frota, part 4: inspections. The company's checklist (ready items + its own), the inspections
     * (photos, odometer and the answer of each item) and the link from a problem to the maintenance request.
     */
    public function up(): void
    {
        // Items of the checklist. Ready items have a "key" (name translated); the company's own have a "label".
        Schema::create('fleet_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('key', 40)->nullable();
            $table->string('label')->nullable();
            // A problem in a critical item sends the vehicle to "in maintenance".
            $table->boolean('is_critical')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'key']);
        });

        Schema::create('fleet_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('vehicle_id');
            $table->timestamp('performed_at');
            $table->unsignedInteger('odometer');
            // {"front": path, "back": path, "left": path, "right": path, "dashboard": path}
            $table->json('photos');
            // [{"item_id", "label", "critical", "ok", "note", "photo"}] as it was answered (history never changes).
            $table->json('answers');
            $table->unsignedSmallInteger('problems')->default(0);
            $table->boolean('has_critical')->default(false);
            $table->text('notes')->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'vehicle_id'])->references(['tenant_id', 'id'])->on('fleet_vehicles')->cascadeOnDelete();
            $table->index(['tenant_id', 'vehicle_id', 'performed_at']);
        });

        // A problem found in an inspection becomes a maintenance request.
        Schema::table('fleet_service_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('inspection_id')->nullable()->after('photo_path');
            $table->foreign(['tenant_id', 'inspection_id'])->references(['tenant_id', 'id'])->on('fleet_inspections')->noActionOnDelete();
        });

        // How often the company wants an inspection: daily, weekly, monthly or free.
        Schema::table('fleet_settings', function (Blueprint $table) {
            $table->string('inspection_frequency', 10)->default('daily');
        });
    }

    public function down(): void
    {
        Schema::table('fleet_settings', function (Blueprint $table) {
            $table->dropColumn('inspection_frequency');
        });
        Schema::table('fleet_service_requests', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'inspection_id']);
            $table->dropColumn('inspection_id');
        });
        Schema::dropIfExists('fleet_inspections');
        Schema::dropIfExists('fleet_checklist_items');
    }
};
