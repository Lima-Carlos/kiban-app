<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The customer's own page (no login): quotes, service orders, reports and PMOC equipment in one link. */
    public function up(): void
    {
        Schema::table('fd_customers', function (Blueprint $table) {
            $table->string('portal_token', 48)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('fd_customers', function (Blueprint $table) {
            $table->dropColumn('portal_token');
        });
    }
};
