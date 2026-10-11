<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Demonstration module (task lists): the model every real module (Frota, Ponto...) follows.
     */
    public function up(): void
    {
        Schema::create('task_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();

            $table->index(['tenant_id', 'name']);
            // Lets other tables point to "this list of this company" (see tasks below).
            $table->unique(['tenant_id', 'id']);
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('task_list_id');
            // Isolation in the database itself: a task can only point to a list of the same company.
            $table->foreign(['tenant_id', 'task_list_id'])->references(['tenant_id', 'id'])->on('task_lists')->cascadeOnDelete();
            $table->string('title');
            $table->text('notes')->nullable();
            $table->date('due_on')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('task_lists');
    }
};
