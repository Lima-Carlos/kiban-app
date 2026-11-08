<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ready expense categories keep no name of their own: an empty name shows the ready name in the language of whoever
 * is looking. Before this, they were saved in the language of the first person who opened the screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ex_categories', function (Blueprint $table) {
            $table->string('name', 80)->nullable()->change();
        });

        foreach (DB::table('ex_categories')->whereNotNull('key')->get(['id', 'key', 'name']) as $row) {
            $ready = collect(array_keys(config('kiban.locales')))->map(fn (string $locale): string => __('expenses.defaults.'.$row->key, [], $locale));

            if ($ready->contains($row->name)) {
                DB::table('ex_categories')->where('id', $row->id)->update(['name' => null]);
            }
        }
    }

    public function down(): void
    {
        foreach (DB::table('ex_categories')->whereNull('name')->get(['id', 'key']) as $row) {
            DB::table('ex_categories')->where('id', $row->id)->update(['name' => __('expenses.defaults.'.$row->key)]);
        }

        Schema::table('ex_categories', function (Blueprint $table) {
            $table->string('name', 80)->nullable(false)->change();
        });
    }
};
