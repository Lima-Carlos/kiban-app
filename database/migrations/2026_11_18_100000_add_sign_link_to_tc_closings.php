<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Closing of the month, round 2:
     * - each closing gets a long random link so the employee signs without a login (sent by WhatsApp);
     * - how the signature was made ("login" in My time clock or "link") and the last reminder sent;
     * - the company may close the month by itself on a chosen day (auto_close_day; empty = off).
     */
    public function up(): void
    {
        Schema::table('tc_closings', function (Blueprint $table) {
            $table->string('sign_token', 64)->nullable()->unique();
            $table->string('signature_method', 10)->nullable();
            $table->timestamp('reminded_at')->nullable();
        });

        Schema::table('tc_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('auto_close_day')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tc_closings', function (Blueprint $table) {
            $table->dropColumn(['sign_token', 'signature_method', 'reminded_at']);
        });

        Schema::table('tc_settings', function (Blueprint $table) {
            $table->dropColumn('auto_close_day');
        });
    }
};
