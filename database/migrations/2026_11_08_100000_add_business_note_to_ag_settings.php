<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ag_settings', function (Blueprint $table): void {
            $table->string('business_note', 300)->nullable()->after('business');
        });
    }

    public function down(): void
    {
        Schema::table('ag_settings', function (Blueprint $table): void {
            $table->dropColumn('business_note');
        });
    }
};
