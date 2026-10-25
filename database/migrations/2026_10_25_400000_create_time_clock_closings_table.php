<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kiban Ponto, part 5: the closing of the month. Once closed, a copy of the calculation is frozen (the time
     * sheet shown and printed is always that copy) and the days of that month do not change any more. The
     * employee signs it electronically or disagrees with a reason; it can be reopened only before the signature.
     * Also the employer data for the official files in Brazil (AFD).
     */
    public function up(): void
    {
        Schema::create('tc_closings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('employee_id');
            // First day of the month closed.
            $table->date('month');
            // The frozen copy: every day and the totals (and the hour bank at the end of the month).
            $table->json('days');
            $table->json('totals');
            $table->json('bank')->nullable();
            // SHA-256 of the frozen copy (printed on the time sheet; any change would show).
            $table->string('snapshot_hash', 64);
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at');
            // Electronic signature of the employee.
            $table->foreignId('signed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('signed_at')->nullable();
            $table->string('signature_ip', 45)->nullable();
            $table->string('signature_device')->nullable();
            $table->string('signature_hash', 64)->nullable();
            // The employee does not agree (the manager may reopen and correct).
            $table->timestamp('disputed_at')->nullable();
            $table->text('dispute_reason')->nullable();
            // Reopened closings stay in the history.
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->text('reopen_reason')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'employee_id'])->references(['tenant_id', 'id'])->on('tc_employees')->noActionOnDelete();
            $table->index(['tenant_id', 'month']);
        });

        // Only one closing in force per employee and month.
        DB::statement('create unique index tc_closings_current_unique on tc_closings (tenant_id, employee_id, month) where reopened_at is null');

        Schema::table('tc_settings', function (Blueprint $table) {
            // Brazil: the employer in the official files (CNPJ or CPF and the legal name).
            $table->string('employer_document_type', 4)->nullable();
            $table->string('employer_document', 14)->nullable();
            $table->string('employer_name', 150)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tc_settings', function (Blueprint $table) {
            $table->dropColumn(['employer_document_type', 'employer_document', 'employer_name']);
        });
        Schema::dropIfExists('tc_closings');
    }
};
