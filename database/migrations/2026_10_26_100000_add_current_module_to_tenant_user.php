<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * System switcher: the last system (module) each person used in each company. The menu and the start page
     * show that system next time.
     */
    public function up(): void
    {
        Schema::table('tenant_user', function (Blueprint $table) {
            $table->string('current_module', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_user', function (Blueprint $table) {
            $table->dropColumn('current_module');
        });
    }
};
