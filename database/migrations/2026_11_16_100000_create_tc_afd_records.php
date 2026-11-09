<?php

use App\Modules\TimeClock\Afd\AfdLine;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Brazil, complete AFD (layout 004): the official line and SHA-256 of each punch (type 7), kept when the punch is
     * made, and the other records of the same numbering (NSR): employer (type 2) and employees (type 5).
     * The old punches get their line now (no official file was delivered before this).
     */
    public function up(): void
    {
        Schema::create('tc_afd_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // Same numbering as the punches (one sequence per company).
            $table->unsignedBigInteger('nsr');
            // 2 employer, 5 employee.
            $table->unsignedTinyInteger('type');
            $table->timestamp('recorded_at');
            // The official line, with the CRC (never changed).
            $table->string('line', 400);
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['tenant_id', 'nsr']);
            $table->index(['tenant_id', 'recorded_at']);
        });

        Schema::table('tc_punches', function (Blueprint $table) {
            // Positions 1 to 73 of the type 7 record and its SHA-256 (also printed on the receipt).
            $table->string('afd_line', 80)->nullable();
            $table->string('afd_hash', 64)->nullable();
        });

        Schema::table('tc_settings', function (Blueprint $table) {
            $table->string('last_afd_hash', 64)->nullable();
        });

        $this->fillOldPunches();
    }

    protected function fillOldPunches(): void
    {
        foreach (DB::table('tc_settings')->get(['id', 'tenant_id']) as $settings) {
            $previous = null;

            DB::table('tc_punches')
                ->leftJoin('tc_employees', 'tc_employees.id', '=', 'tc_punches.employee_id')
                ->leftJoin('people', 'people.id', '=', 'tc_employees.person_id')
                ->where('tc_punches.tenant_id', $settings->tenant_id)
                ->orderBy('tc_punches.nsr')
                ->select(['tc_punches.id', 'tc_punches.nsr', 'tc_punches.punched_at', 'tc_punches.created_at', 'tc_punches.timezone', 'tc_punches.source', 'tc_punches.flags', 'people.document'])
                ->chunk(500, function ($punches) use (&$previous): void {
                    foreach ($punches as $punch) {
                        $at = CarbonImmutable::parse($punch->punched_at, 'UTC')->setTimezone($punch->timezone);
                        $recorded = $punch->created_at ? CarbonImmutable::parse($punch->created_at, 'UTC')->setTimezone($punch->timezone) : $at;
                        $line = AfdLine::punch((int) $punch->nsr, $at, $punch->document, $recorded,
                            $punch->source === 'kiosk' ? AfdLine::COLLECTOR_BROWSER : AfdLine::COLLECTOR_APP,
                            in_array('offline', (array) json_decode((string) $punch->flags, true), true));
                        $previous = AfdLine::punchHash($line, $previous);

                        DB::table('tc_punches')->where('id', $punch->id)->update(['afd_line' => $line, 'afd_hash' => $previous]);
                    }
                });

            DB::table('tc_settings')->where('id', $settings->id)->update(['last_afd_hash' => $previous]);
        }
    }

    public function down(): void
    {
        Schema::table('tc_settings', function (Blueprint $table) {
            $table->dropColumn('last_afd_hash');
        });

        Schema::table('tc_punches', function (Blueprint $table) {
            $table->dropColumn(['afd_line', 'afd_hash']);
        });

        Schema::dropIfExists('tc_afd_records');
    }
};
