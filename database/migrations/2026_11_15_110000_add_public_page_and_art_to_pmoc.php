<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * PMOC: the QR label opens a page without login (public_token, long and random) and the plan keeps the ART/TRT
     * of the responsible technician (number and file).
     */
    public function up(): void
    {
        Schema::table('pm_equipment', function (Blueprint $table) {
            $table->string('public_token', 48)->nullable()->unique();
        });

        DB::table('pm_equipment')->whereNull('public_token')->orderBy('id')->each(function (object $row): void {
            DB::table('pm_equipment')->where('id', $row->id)->update(['public_token' => Str::random(48)]);
        });

        Schema::table('pm_plans', function (Blueprint $table) {
            $table->string('art_number', 60)->nullable();
            $table->string('art_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pm_plans', function (Blueprint $table) {
            $table->dropColumn(['art_number', 'art_path']);
        });

        Schema::table('pm_equipment', function (Blueprint $table) {
            $table->dropColumn('public_token');
        });
    }
};
