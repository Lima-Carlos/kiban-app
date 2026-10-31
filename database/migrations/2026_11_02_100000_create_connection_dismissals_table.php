<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Do not show again" of the window "your systems now work together": one row per person, company and
 * connection. A connection added in a later version has no row yet, so it shows up even for who dismissed the
 * older ones; the same login in another company sees it there too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connection_dismissals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('connection', 60);
            $table->timestamp('created_at')->nullable();

            $table->unique(['tenant_id', 'user_id', 'connection']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connection_dismissals');
    }
};
