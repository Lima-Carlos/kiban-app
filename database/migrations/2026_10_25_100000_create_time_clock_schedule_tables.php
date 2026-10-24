<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kiban Ponto, part 2: work schedules (weekly or rotating, like 12x36), which schedule each employee
     * follows from which date (history), the company's holidays and the calculation rules of the company.
     */
    public function up(): void
    {
        Schema::create('tc_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // "weekly" (each weekday its hours) or "cycle" (work N days, rest M days, e.g. 12x36).
            $table->string('type', 10);
            // weekly: {"1": {"start","break_start","break_end","end"} | null, ... "7": ...} (1 = Monday)
            $table->json('week')->nullable();
            // cycle: hours of each working day and the rhythm, counted from a reference date.
            $table->unsignedSmallInteger('work_days')->nullable();
            $table->unsignedSmallInteger('off_days')->nullable();
            $table->string('start_time', 5)->nullable();
            $table->string('end_time', 5)->nullable();
            $table->unsignedSmallInteger('break_minutes')->nullable();
            $table->date('anchor_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
        });

        // Which schedule an employee follows from which day (changes never touch past days).
        Schema::create('tc_employee_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('schedule_id');
            $table->date('starts_on');
            $table->timestamps();

            $table->unique(['tenant_id', 'employee_id', 'starts_on']);
            $table->foreign(['tenant_id', 'employee_id'])->references(['tenant_id', 'id'])->on('tc_employees')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'schedule_id'])->references(['tenant_id', 'id'])->on('tc_schedules')->noActionOnDelete();
        });

        // Holidays of the company: state, city, the company's own. National ones are calculated (not stored).
        Schema::create('tc_holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('name');
            // Same day every year (e.g. a city's anniversary).
            $table->boolean('recurring')->default(false);
            // Only for one workplace (e.g. a holiday of the city of that branch); empty = the whole company.
            $table->unsignedBigInteger('workplace_id')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'workplace_id'])->references(['tenant_id', 'id'])->on('tc_workplaces')->cascadeOnDelete();
        });

        // Calculation rules (used from part 4 on). Brazil (CLT): the legal values, the company may only improve them.
        Schema::table('tc_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('overtime_percent')->default(50);
            $table->unsignedSmallInteger('rest_day_overtime_percent')->default(100);
            $table->string('night_start', 5)->default('22:00');
            $table->string('night_end', 5)->default('05:00');
            $table->unsignedSmallInteger('night_percent')->default(20);
            $table->boolean('night_reduced_hour')->default(true);
            $table->unsignedSmallInteger('tolerance_punch_minutes')->default(5);
            $table->unsignedSmallInteger('tolerance_day_minutes')->default(10);
            $table->boolean('hour_bank')->default(false);
            $table->unsignedSmallInteger('hour_bank_months')->default(6);
            // Optional days off of the company: Good Friday, Carnival, Corpus Christi.
            $table->json('optional_holidays')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tc_settings', function (Blueprint $table) {
            $table->dropColumn([
                'overtime_percent', 'rest_day_overtime_percent', 'night_start', 'night_end', 'night_percent', 'night_reduced_hour',
                'tolerance_punch_minutes', 'tolerance_day_minutes', 'hour_bank', 'hour_bank_months', 'optional_holidays',
            ]);
        });
        Schema::dropIfExists('tc_holidays');
        Schema::dropIfExists('tc_employee_schedules');
        Schema::dropIfExists('tc_schedules');
    }
};
