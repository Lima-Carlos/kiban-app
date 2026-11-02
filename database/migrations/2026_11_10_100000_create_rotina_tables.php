<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kiban Core Rotina ("Daily Log"): projects (with the client's name), the day's tasks of each person and the
     * hours worked per project. Not a work-hours control (that is the Ponto).
     */
    public function up(): void
    {
        Schema::create('ro_projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('client_name', 160)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
        });

        Schema::create('ro_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('title', 255);
            $table->date('due_on')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->timestamps();

            $table->foreign(['tenant_id', 'project_id'])->references(['tenant_id', 'id'])->on('ro_projects')->noActionOnDelete();
            $table->index(['tenant_id', 'user_id', 'due_on']);
        });

        Schema::create('ro_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->date('worked_on');
            $table->unsignedInteger('minutes')->default(0);
            $table->string('note', 255)->nullable();
            // Set while the stopwatch is running; stopping turns the time into minutes.
            $table->timestamp('started_at')->nullable();
            $table->timestamps();

            $table->foreign(['tenant_id', 'project_id'])->references(['tenant_id', 'id'])->on('ro_projects')->noActionOnDelete();
            $table->index(['tenant_id', 'worked_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ro_entries');
        Schema::dropIfExists('ro_tasks');
        Schema::dropIfExists('ro_projects');
    }
};
