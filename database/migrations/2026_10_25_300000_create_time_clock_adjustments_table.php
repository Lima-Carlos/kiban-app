<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kiban Ponto, part 4: corrections of a day, always apart from the punches (which never change), with the
     * reason: a forgotten punch, a punch to leave out, or an excused time (medical certificate...). The employee
     * asks and the manager approves; one made by the manager is approved at once.
     */
    public function up(): void
    {
        Schema::create('tc_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('employee_id');
            // The working day it belongs to (local date).
            $table->date('date');
            // add_punch (forgotten punch), ignore_punch (a wrong punch left out), excuse (excused time).
            $table->string('type', 20);
            // add_punch: the moment of the forgotten punch (UTC).
            $table->timestamp('punch_at')->nullable();
            // ignore_punch: which punch is left out (it stays in the history).
            $table->unsignedBigInteger('punch_id')->nullable();
            // excuse: minutes excused; empty = the whole day.
            $table->unsignedSmallInteger('minutes')->nullable();
            $table->text('reason');
            $table->string('attachment_path')->nullable();
            // pending → approved | rejected
            $table->string('status', 10)->default('pending');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('response')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'employee_id'])->references(['tenant_id', 'id'])->on('tc_employees')->noActionOnDelete();
            $table->foreign(['tenant_id', 'punch_id'])->references(['tenant_id', 'id'])->on('tc_punches')->noActionOnDelete();
            $table->index(['tenant_id', 'employee_id', 'date']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tc_adjustments');
    }
};
