<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The file for the payroll program of the accountant: which program, the company code there and the code of each event. */
    public function up(): void
    {
        Schema::table('tc_settings', function (Blueprint $table) {
            $table->json('payroll')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tc_settings', function (Blueprint $table) {
            $table->dropColumn('payroll');
        });
    }
};
