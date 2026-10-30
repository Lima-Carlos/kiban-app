<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Punches without signal: the phone keeps each punch in a queue with its own code (client_id) and sends it when
 * the signal comes back. The code makes a punch sent twice (e.g. the answer was lost) count only once.
 * The time the server received it is created_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tc_punches', function (Blueprint $table) {
            $table->uuid('client_id')->nullable();
            $table->unique(['tenant_id', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::table('tc_punches', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'client_id']);
            $table->dropColumn('client_id');
        });
    }
};
