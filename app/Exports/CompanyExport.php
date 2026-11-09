<?php

namespace App\Exports;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * "Download everything": all the company's data in one .zip, so the customer always owns their data (and can leave
 * whenever they want, which makes it easier to come in):
 * - one spreadsheet (CSV with ";", opens in Excel) per kind of record: every table with the company's records;
 * - the files: photos, receipts, signatures, attachments and PDFs kept for the company;
 * - LEIA-ME.txt explaining the contents.
 * Secrets (access tokens, PIN codes, passwords) never go. Built in the background (ExportCompanyData); the link
 * in the bell works for 7 days and only for the owner and the administrators.
 */
class CompanyExport
{
    public const KEEP_DAYS = 7;

    /** Columns never exported (secrets that open something). */
    protected const SECRET = '/(token|secret|password|pin_lookup|pin_hash|remember)/i';

    /** Tables kept only for the system itself. */
    protected const SKIP = ['notice_log', 'sessions', 'cache', 'jobs', 'failed_jobs'];

    public static function folder(Tenant $tenant): string
    {
        return 'exports/'.$tenant->getKey();
    }

    /** Builds the .zip; returns its name (inside the company's export folder). */
    public function build(Tenant $tenant): string
    {
        $disk = Storage::disk('local');
        $name = 'kiban-dados-'.Str::slug($tenant->subdomain).'-'.now()->format('Y-m-d-His').'-'.Str::lower(Str::random(6)).'.zip';
        $disk->makeDirectory(static::folder($tenant));
        $path = $disk->path(static::folder($tenant).'/'.$name);

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the export file.');
        }

        $tables = [];

        foreach ($this->tables() as $table) {
            $rows = $this->csv($table, $tenant);

            if ($rows !== null) {
                $zip->addFromString('planilhas/'.$table.'.csv', $rows['csv']);
                $tables[$table] = $rows['count'];
            }
        }

        $files = 0;

        foreach (['local', 'public'] as $diskName) {
            $source = Storage::disk($diskName);
            $root = 'tenants/'.$tenant->getKey();

            if (! $source->exists($root)) {
                continue;
            }

            foreach ($source->allFiles($root) as $file) {
                $zip->addFile($source->path($file), 'arquivos/'.substr($file, strlen($root) + 1));
                $files++;
            }
        }

        $zip->addFromString('LEIA-ME.txt', $this->readme($tenant, $tables, $files));
        $zip->close();

        return $name;
    }

    /** Deletes the exports older than KEEP_DAYS. */
    public function cleanup(): int
    {
        $disk = Storage::disk('local');
        $removed = 0;

        foreach ($disk->allFiles('exports') as $file) {
            if ($disk->lastModified($file) < now()->subDays(self::KEEP_DAYS)->getTimestamp()) {
                $disk->delete($file);
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Every table with a tenant_id column.
     *
     * @return array<int, string>
     */
    protected function tables(): array
    {
        return collect(Schema::getTables())
            ->pluck('name')
            ->reject(fn (string $table): bool => in_array($table, self::SKIP, true))
            ->filter(fn (string $table): bool => Schema::hasColumn($table, 'tenant_id'))
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return array{csv: string, count: int}|null
     */
    protected function csv(string $table, Tenant $tenant): ?array
    {
        $columns = array_values(array_filter(Schema::getColumnListing($table), fn (string $column): bool => ! preg_match(self::SECRET, $column)));
        $query = DB::table($table)->where('tenant_id', $tenant->getKey())->select($columns);

        if (in_array('id', $columns, true)) {
            $query->orderBy('id');
        }

        $count = (clone $query)->count();

        if ($count === 0) {
            return null;
        }

        $handle = fopen('php://temp', 'w+');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, $columns, ';', escape: '');

        foreach ($query->cursor() as $row) {
            fputcsv($handle, array_map(fn ($value): string => is_bool($value) ? ($value ? '1' : '0') : (string) $value, (array) $row), ';', escape: '');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return ['csv' => $csv, 'count' => $count];
    }

    /** @param  array<string, int>  $tables */
    protected function readme(Tenant $tenant, array $tables, int $files): string
    {
        $lines = [
            __('app.export.readme_title', ['company' => $tenant->name, 'date' => now()->translatedFormat(__('app.date_format'))]),
            '',
            __('app.export.readme_sheets'),
            ...array_map(fn (string $table, int $count): string => '- planilhas/'.$table.'.csv ('.$count.')', array_keys($tables), $tables),
            '',
            __('app.export.readme_files', ['count' => $files]),
            '',
            __('app.export.readme_secrets'),
        ];

        return implode("\r\n", $lines)."\r\n";
    }
}
