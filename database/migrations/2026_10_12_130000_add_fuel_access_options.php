<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who may fuel which vehicle:
     * - a driver marked "any vehicle" (e.g. a backup driver) uses any active vehicle;
     * - if the company allows it, a driver can fuel a vehicle that is not theirs, with a reason (flagged for the manager).
     */
    public function up(): void
    {
        Schema::table('fleet_drivers', function (Blueprint $table) {
            $table->boolean('any_vehicle')->default(false)->after('is_active');
        });

        Schema::table('fleet_settings', function (Blueprint $table) {
            $table->boolean('allow_other_vehicle')->default(true)->after('alert_days');
        });

        Schema::table('fleet_fuel_ups', function (Blueprint $table) {
            // Why the person fueled a vehicle that is not theirs.
            $table->string('reason')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('fleet_fuel_ups', fn (Blueprint $table) => $table->dropColumn('reason'));
        Schema::table('fleet_settings', fn (Blueprint $table) => $table->dropColumn('allow_other_vehicle'));
        Schema::table('fleet_drivers', fn (Blueprint $table) => $table->dropColumn('any_vehicle'));
    }
};
