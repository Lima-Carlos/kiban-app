<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Integrations" page opened: one row per person, company and connection seen there. Feeds the "new" counter of
 * the menu and the note on the start page (a connection added later has no row yet, so it counts as new again).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connection_views', function (Blueprint $table) {
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
        Schema::dropIfExists('connection_views');
    }
};
