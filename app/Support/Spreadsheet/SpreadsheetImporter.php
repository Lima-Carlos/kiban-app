<?php

namespace App\Support\Spreadsheet;

use DateTimeInterface;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Records from a spreadsheet (Excel .xlsx or .csv), e.g. exported from the system the company used before,
 * so changing to the Kiban costs no typing:
 * - the first line has the column names, in any of the 3 languages (the model to download has them right) or
 *   common other names (aliases); unknown columns are ignored and the order does not matter;
 * - each line is checked by the importer with the same rules as the form; a wrong line is skipped and reported
 *   with its number, the others are saved;
 * - only the first sheet; at most MAX_ROWS lines.
 * The customers of the Campo have their own importer (CustomerImporter), written before this class.
 */
abstract class SpreadsheetImporter
{
    public const MAX_ROWS = 5000;

    /** @return array<int, string> the known columns */
    abstract protected function columnKeys(): array;

    /** The column that must exist (e.g. "name"). */
    abstract protected function requiredColumn(): string;

    /** Prefix of the translations: "<prefix>.columns.<key>", "<prefix>.example.<key>", "<prefix>.no_column"... */
    abstract protected function langPrefix(): string;

    /**
     * One line: true = created, false = updated, string = why it was skipped.
     *
     * @param  array<string, ?string>  $data
     */
    abstract protected function line(array $data): bool|string;

    /** @return array<string, array<int, string>> other names people use for the columns */
    protected function aliases(): array
    {
        return [];
    }

    /**
     * @return array{created: int, updated: int, errors: array<int, array{line: int, message: string}>}
     */
    public function import(string $path, string $extension): array
    {
        $result = ['created' => 0, 'updated' => 0, 'errors' => []];
        $reader = strtolower($extension) === 'csv' ? new CsvReader($this->csvOptions($path)) : new XlsxReader;
        $reader->open($path);

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $columns = null;
                $line = 0;

                foreach ($sheet->getRowIterator() as $row) {
                    $line++;
                    $cells = $row->toArray();

                    if ($columns === null) {
                        $columns = $this->columns($cells);

                        if (! in_array($this->requiredColumn(), $columns, true)) {
                            $result['errors'][] = ['line' => 1, 'message' => __($this->langPrefix().'.no_column', ['column' => __($this->langPrefix().'.columns.'.$this->requiredColumn())])];

                            return $result;
                        }

                        continue;
                    }

                    if (collect($cells)->filter(fn ($value): bool => filled($value))->isEmpty()) {
                        continue;
                    }

                    if ($line - 1 > static::MAX_ROWS) {
                        $result['errors'][] = ['line' => $line, 'message' => __('app.import.too_many', ['max' => static::MAX_ROWS])];

                        return $result;
                    }

                    $data = [];

                    foreach ($columns as $index => $key) {
                        if ($key !== null && array_key_exists($index, $cells)) {
                            $value = $cells[$index];
                            $data[$key] = match (true) {
                                $value instanceof DateTimeInterface => $value->format('Y-m-d'),
                                is_scalar($value) => trim((string) $value),
                                default => null,
                            };
                        }
                    }

                    $outcome = $this->line($data);

                    if (is_string($outcome)) {
                        $result['errors'][] = ['line' => $line, 'message' => $outcome];
                    } else {
                        $result[$outcome ? 'created' : 'updated']++;
                    }
                }

                break;
            }
        } finally {
            $reader->close();
        }

        return $result;
    }

    /** The model to download: the column names in the person's language and one example line. */
    public function template(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kiban').'.xlsx';
        $writer = new XlsxWriter;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(array_map(fn (string $key): string => __($this->langPrefix().'.columns.'.$key), $this->columnKeys())));
        $writer->addRow(Row::fromValues(array_map(fn (string $key): string => (string) __($this->langPrefix().'.example.'.$key), $this->columnKeys())));
        $writer->close();

        return $path;
    }

    /** "sim", "yes", "x", "1"… = true. */
    protected function yes(?string $value): bool
    {
        return in_array(Str::slug((string) $value, '_'), ['sim', 's', 'yes', 'y', 'si', 'x', '1', 'true', 'verdadeiro'], true);
    }

    /** A date typed as 31/12/2026, 2026-12-31 or an Excel date. */
    protected function date(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'd/m/y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!'.$format, $value);

            if ($date !== false && $date->format($format) === $value) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    /**
     * Which known column each spreadsheet column is (null = ignored).
     *
     * @param  array<int, mixed>  $headers
     * @return array<int, ?string>
     */
    protected function columns(array $headers): array
    {
        $known = [];

        foreach ($this->columnKeys() as $key) {
            $known[$key] = $key;

            foreach (array_keys(config('kiban.locales')) as $locale) {
                $known[Str::slug(__($this->langPrefix().'.columns.'.$key, [], $locale), '_')] = $key;
            }
        }

        foreach ($this->aliases() as $key => $aliases) {
            foreach ($aliases as $alias) {
                $known[$alias] ??= $key;
            }
        }

        $taken = [];

        return array_map(function ($header) use ($known, &$taken): ?string {
            $key = $known[Str::slug((string) $header, '_')] ?? null;

            if ($key === null || isset($taken[$key])) {
                return null;
            }

            $taken[$key] = true;

            return $key;
        }, $headers);
    }

    /** Excel in Brazil saves CSV with ";"; elsewhere usually ",". Old Excel files are not UTF-8. */
    protected function csvOptions(string $path): CsvOptions
    {
        $handle = fopen($path, 'r');
        $first = (string) fgets($handle);
        fclose($handle);

        $options = new CsvOptions;
        $options->FIELD_DELIMITER = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';

        $sample = (string) file_get_contents($path, length: 65536);
        $sample = substr($sample, 0, strrpos($sample, "\n") ?: strlen($sample));

        if (! mb_check_encoding($sample, 'UTF-8')) {
            $options->ENCODING = 'Windows-1252';
        }

        return $options;
    }
}
