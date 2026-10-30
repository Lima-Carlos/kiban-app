<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marco Civil (art. 15; Decree 8.771): the access records of the application. One line per session and per day,
 * with IP, source port and device, kept in secret for at least 6 months (kiban.access_log_days). No foreign keys:
 * the record must survive even when the company or the person is removed.
 * Also the IP and port of each punch (the tablet has no login, so the punch itself is its access record).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('tenant_subdomain', 100)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_email')->nullable();
            $table->string('session_hash', 64);
            $table->date('day');
            $table->string('ip', 45)->nullable();
            $table->unsignedInteger('port')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('first_at');
            $table->timestamp('last_at');

            $table->unique(['session_hash', 'day']);
            $table->index(['user_id', 'day']);
            $table->index('day');
        });

        Schema::table('tc_punches', function (Blueprint $table) {
            $table->string('ip', 45)->nullable();
            $table->unsignedInteger('port')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_logs');
        Schema::table('tc_punches', function (Blueprint $table) {
            $table->dropColumn(['ip', 'port']);
        });
    }
};
