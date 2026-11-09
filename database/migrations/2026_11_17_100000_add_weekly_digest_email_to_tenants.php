<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The weekly summary also goes by e-mail (through the Core) unless the company turns it off. */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('weekly_digest_email')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('weekly_digest_email');
        });
    }
};
