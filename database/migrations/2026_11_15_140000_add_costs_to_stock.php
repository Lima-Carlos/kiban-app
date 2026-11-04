<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Estoque: the cost of each entry and the average cost of each item, for the value kept in each place. */
    public function up(): void
    {
        Schema::table('es_items', function (Blueprint $table) {
            $table->unsignedInteger('avg_cost_cents')->nullable();
        });

        Schema::table('es_movements', function (Blueprint $table) {
            $table->unsignedInteger('unit_cost_cents')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('es_movements', function (Blueprint $table) {
            $table->dropColumn('unit_cost_cents');
        });

        Schema::table('es_items', function (Blueprint $table) {
            $table->dropColumn('avg_cost_cents');
        });
    }
};
