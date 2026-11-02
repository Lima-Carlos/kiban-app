<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The company chooses whether the punch takes a photo: on the phone and on the company tablet, apart. */
    public function up(): void
    {
        Schema::table('tc_settings', function (Blueprint $table): void {
            $table->boolean('photo_on_phone')->default(true);
            $table->boolean('photo_on_kiosk')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('tc_settings', function (Blueprint $table): void {
            $table->dropColumn(['photo_on_phone', 'photo_on_kiosk']);
        });
    }
};
