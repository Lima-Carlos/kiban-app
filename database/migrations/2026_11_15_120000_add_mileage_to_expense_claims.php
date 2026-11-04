<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Despesas: mileage with one's own vehicle (km × the company's value per km). */
    public function up(): void
    {
        Schema::table('ex_categories', function (Blueprint $table) {
            $table->unsignedInteger('per_km_cents')->nullable();
        });

        Schema::table('ex_claims', function (Blueprint $table) {
            $table->decimal('km', 9, 1)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ex_claims', function (Blueprint $table) {
            $table->dropColumn('km');
        });

        Schema::table('ex_categories', function (Blueprint $table) {
            $table->dropColumn('per_km_cents');
        });
    }
};
