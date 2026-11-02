<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The vehicle's km at the technician's arrival is now optional (off by default): at the customer's door the
     * technician rarely remembers it. The Frota still gets the km from fuel-ups and inspections.
     */
    public function up(): void
    {
        Schema::table('fd_settings', function (Blueprint $table): void {
            $table->boolean('ask_km')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('fd_settings', function (Blueprint $table): void {
            $table->dropColumn('ask_km');
        });
    }
};
