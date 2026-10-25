<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kiban Ponto: privacy notice of the punch photos (LGPD). The first time someone punches with a photo, they
     * read why the photo is taken, who sees it and how long it is kept, and their acknowledgement ("I am aware",
     * not consent) is recorded here, never changed. A new version of the notice asks again.
     * The company chooses whether the "Punch without photo" button appears and may add a contact for questions.
     */
    public function up(): void
    {
        Schema::create('tc_photo_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('employee_id');
            // Which version of the notice was shown (wording, retention and contact).
            $table->string('version', 64);
            $table->unsignedSmallInteger('retention_months');
            // "phone" or "kiosk".
            $table->string('source', 10);
            $table->unsignedBigInteger('kiosk_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ip', 45)->nullable();
            $table->string('device')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'employee_id'])->references(['tenant_id', 'id'])->on('tc_employees')->noActionOnDelete();
            $table->foreign(['tenant_id', 'kiosk_id'])->references(['tenant_id', 'id'])->on('tc_kiosks')->noActionOnDelete();
            $table->index(['tenant_id', 'employee_id', 'version']);
        });

        Schema::table('tc_settings', function (Blueprint $table) {
            $table->boolean('allow_punch_without_photo')->default(true);
            $table->string('privacy_contact', 150)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tc_settings', function (Blueprint $table) {
            $table->dropColumn(['allow_punch_without_photo', 'privacy_contact']);
        });
        Schema::dropIfExists('tc_photo_notices');
    }
};
