<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cities of the company for the holidays: a holiday can be for one city, a workplace has a city and an
     * employee can follow another one (e.g. works from home in another city).
     */
    public function up(): void
    {
        Schema::create('tc_cities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('state', 40)->nullable();
            // Official IBGE code (Brazil), used to find the suggested holidays.
            $table->unsignedInteger('ibge_code')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
        });

        foreach (['tc_holidays', 'tc_workplaces', 'tc_employees'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->unsignedBigInteger('city_id')->nullable();
                $table->foreign(['tenant_id', 'city_id'])->references(['tenant_id', 'id'])->on('tc_cities')->noActionOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['tc_holidays', 'tc_workplaces', 'tc_employees'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropForeign(['tenant_id', 'city_id']);
                $table->dropColumn('city_id');
            });
        }

        Schema::dropIfExists('tc_cities');
    }
};
