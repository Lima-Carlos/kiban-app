<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The central record of the company's people (any module uses it) and the company's teams.
     * A person may have a login (a Core user) or not (e.g. someone who only clocks in on the company tablet).
     * People who already belong to a company get their record now.
     */
    public function up(): void
    {
        // Teams / sectors (e.g. "Installation team", "Office"), shared by the modules.
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('people', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            // Brazil: "cpf" (digits only, checked). Other countries: what the company calls it (e.g. "DNI", "NIF").
            $table->string('document_type', 20)->nullable();
            $table->string('document', 40)->nullable();
            $table->unsignedBigInteger('team_id')->nullable();
            // Left the company: the record and its history stay.
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'user_id']);
            $table->unique(['tenant_id', 'document_type', 'document']);
            $table->foreign(['tenant_id', 'team_id'])->references(['tenant_id', 'id'])->on('teams')->noActionOnDelete();
        });

        $now = now();
        $members = DB::table('tenant_user')->join('users', 'users.id', '=', 'tenant_user.user_id')
            ->select('tenant_user.tenant_id', 'users.id', 'users.name', 'users.phone', 'users.email')
            ->get();

        foreach ($members as $member) {
            DB::table('people')->insert([
                'tenant_id' => $member->tenant_id,
                'user_id' => $member->id,
                'name' => $member->name,
                'phone' => $member->phone,
                'email' => $member->email,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('people');
        Schema::dropIfExists('teams');
    }
};
