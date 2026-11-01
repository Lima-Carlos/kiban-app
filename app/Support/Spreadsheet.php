<?php

namespace App\Support;

use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Writes an Excel file (OpenSpout) shared by the reports of every system: money goes as numbers (the
 * spreadsheet adds them up), the first row of each sheet is the header (bold) and a row starting with
 * '__bold__' is bold too (totals). The file is made in storage/app/private/exports and deleted after the download.
 */
class Spreadsheet
{
    /**
     * @param  array<string, array<int, array<int, mixed>>>  $sheets  sheet name => rows
     */
    public static function write(string $fileName, array $sheets): string
    {
        $directory = storage_path('app/private/exports');

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $path = $directory.'/'.Str::slug($fileName).'-'.Str::random(6).'.xlsx';
        $bold = (new Style)->setFontBold();
        $number = (new Style)->setFormat('#,##0.00');
        $numberBold = (new Style)->setFormat('#,##0.00')->setFontBold();

        $writer = new Writer;
        $writer->openToFile($path);
        $first = true;

        foreach ($sheets as $name => $rows) {
            $sheet = $first ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
            $sheet->setName(mb_substr(str_replace(['/', '\', '?', '*', '[', ']', ':'], '-', (string) $name), 0, 31));
            $first = false;

            foreach ($rows as $index => $values) {
                $isBold = $index === 0 || (($values[0] ?? null) === '__bold__');

                if (($values[0] ?? null) === '__bold__') {
                    array_shift($values);
                }

                $cells = array_map(
                    fn (mixed $value): Cell => Cell::fromValue($value, is_float($value) ? ($isBold ? $numberBold : $number) : ($isBold ? $bold : null)),
                    array_values($values),
                );

                $writer->addRow(new Row($cells));
            }
        }

        $writer->close();

        return $path;
    }
}
