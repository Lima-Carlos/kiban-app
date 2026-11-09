<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Brazil: the time clocks the company already has (card, fingerprint, face). Their punches come by the AFD file
     * of the clock (same official layout for every brand) and count like the ones of this program, without a number
     * (NSR) of their own here: they keep the clock's number.
     */
    public function up(): void
    {
        Schema::create('tc_clocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // 1 REP-C (the usual clock), 2 REP-A (by agreement), 3 REP-P (another program).
            $table->unsignedTinyInteger('type')->default(1);
            // Serial number of the clock (REP-C) or registration of the program (REP-P), from the file header.
            $table->string('number', 20);
            $table->unsignedBigInteger('last_nsr')->default(0);
            $table->timestamp('last_import_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'number']);
        });

        Schema::table('tc_punches', function (Blueprint $table) {
            $table->unsignedBigInteger('nsr')->nullable()->change();
            $table->string('hash', 64)->nullable()->change();
            $table->unsignedBigInteger('clock_id')->nullable();
            $table->unsignedBigInteger('clock_nsr')->nullable();

            $table->unique(['tenant_id', 'clock_id', 'clock_nsr']);
            $table->foreign(['tenant_id', 'clock_id'])->references(['tenant_id', 'id'])->on('tc_clocks')->noActionOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tc_punches', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'clock_id']);
            $table->dropUnique(['tenant_id', 'clock_id', 'clock_nsr']);
            $table->dropColumn(['clock_id', 'clock_nsr']);
        });

        Schema::dropIfExists('tc_clocks');
    }
};
