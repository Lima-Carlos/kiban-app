<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Ready types that are courses or trainings (DocumentTemplates). */
    protected const TRAININGS = ['nr10', 'nr35', 'nr33', 'nr11', 'nr12', 'nr06', 'training', 'safety_training'];

    /**
     * Courses and trainings (part of the people's documents, not a module): which document types are trainings,
     * the course details on the document (institution, hours, initial or refresher) and who needs which course
     * (everyone, a team or the people with a role in a system, e.g. the Campo technicians).
     */
    public function up(): void
    {
        Schema::table('person_document_types', function (Blueprint $table) {
            $table->boolean('is_training')->default(false)->after('is_health');
        });

        Schema::table('person_documents', function (Blueprint $table) {
            // Institution or instructor of the course.
            $table->string('provider', 120)->nullable()->after('notes');
            $table->unsignedSmallInteger('workload_hours')->nullable()->after('provider');
            // "initial" or "refresher" (TrainingKind).
            $table->string('training_kind', 20)->nullable()->after('workload_hours');
        });

        Schema::create('person_document_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('document_type_id');
            // "all" (everyone), "team" (team_id) or "role" (module + role, e.g. campo / technician).
            $table->string('scope', 10);
            $table->unsignedBigInteger('team_id')->nullable();
            $table->string('module', 30)->nullable();
            $table->string('role', 30)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'document_type_id']);
            $table->foreign(['tenant_id', 'document_type_id'])->references(['tenant_id', 'id'])->on('person_document_types')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'team_id'])->references(['tenant_id', 'id'])->on('teams')->cascadeOnDelete();
        });

        $this->markTrainings();
    }

    /**
     * Companies that already have their types: the trainings are marked, and they warn 60 days ahead (a class takes
     * time to book) when they still have the old default of 30 days. A value the company chose stays.
     */
    public function markTrainings(): void
    {
        DB::table('person_document_types')->whereIn('key', self::TRAININGS)->update(['is_training' => true]);
        DB::table('person_document_types')->whereIn('key', self::TRAININGS)->where('alert_days', 30)->update(['alert_days' => 60]);
    }

    public function down(): void
    {
        Schema::dropIfExists('person_document_requirements');

        Schema::table('person_documents', function (Blueprint $table) {
            $table->dropColumn(['provider', 'workload_hours', 'training_kind']);
        });

        Schema::table('person_document_types', function (Blueprint $table) {
            $table->dropColumn('is_training');
        });
    }
};
