<?php

namespace App\Console\Commands;

use App\Models\AccessLog;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Marco Civil: the access records are delivered only on a court order. This command exports them (by person, IP and
 * period) to a spreadsheet file (CSV) in storage/app/exports/, for Kiban to hand over. There is no screen for them:
 * the companies never see these records.
 */
class ExportAccessLogs extends Command
{
    protected $signature = 'kiban:access-logs
        {--email= : Only this person}
        {--ip= : Only this IP}
        {--from= : First day (YYYY-MM-DD)}
        {--to= : Last day (YYYY-MM-DD)}';

    protected $description = 'Export the access records (Marco Civil) for a court order';

    public function handle(): int
    {
        $query = AccessLog::query()->orderBy('first_at');

        if ($email = $this->option('email')) {
            $query->where('user_email', mb_strtolower((string) $email));
        }

        if ($ip = $this->option('ip')) {
            $query->where('ip', (string) $ip);
        }

        if ($from = $this->option('from')) {
            $query->where('day', '>=', CarbonImmutable::parse((string) $from)->toDateString());
        }

        if ($to = $this->option('to')) {
            $query->where('day', '<=', CarbonImmutable::parse((string) $to)->toDateString());
        }

        $lines = [['empresa', 'pessoa', 'ip', 'porta', 'aparelho', 'primeiro_acesso_utc', 'ultimo_acesso_utc']];
        foreach ($query->cursor() as $log) {
            $lines[] = [$log->tenant_subdomain, $log->user_email, $log->ip, $log->port, $log->user_agent, $log->first_at->toIso8601ZuluString(), $log->last_at->toIso8601ZuluString()];
        }

        $csv = fopen('php://temp', 'r+');
        foreach ($lines as $line) {
            fputcsv($csv, $line, ';', escape: '');
        }
        rewind($csv);
        $path = 'exports/registros-de-acesso-'.now()->format('Y-m-d-His').'.csv';
        Storage::disk('local')->put($path, (string) stream_get_contents($csv));

        $this->components->info((count($lines) - 1).' registro(s) exportado(s) em storage/app/'.$path);

        return self::SUCCESS;
    }
}
