<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Language chosen inside the app. It wins over the language of the Kiban account (users.locale),
     * which is updated from the Core at every sign-in.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('app_locale', 10)->nullable()->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('app_locale');
        });
    }
};
