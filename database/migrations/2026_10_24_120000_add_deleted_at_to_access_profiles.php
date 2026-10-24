<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A deleted access profile is only hidden, so a suggested profile the company deleted is not offered again
     * (new suggestions appear when the company gets a new system, e.g. "HR" with the time clock).
     */
    public function up(): void
    {
        Schema::table('access_profiles', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('access_profiles', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
