<?php

namespace App\People\Documents;

use App\People\Person;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * The "Courses and trainings" matrix in an Excel spreadsheet (OpenSpout, like the other spreadsheets): one line per
 * person, one column per course, each cell with the situation and the date, colored like the screen. No files.
 */
class TrainingMatrixExporter
{
    /** Cell colors like the screen (green, yellow, red, gray). */
    protected const COLORS = ['ok' => 'D1FAE5', 'soon' => 'FEF3C7', 'overdue' => 'FEE2E2', 'missing' => 'E5E7EB'];

    /**
     * Path of the .xlsx file (deleted after the download).
     *
     * @param  array{types: iterable<DocumentType>, rows: iterable<array{person: Person, cells: array<int, array{status: string, document: ?PersonDocument, required: bool}>}>}  $matrix
     */
    public function export(array $matrix): string
    {
        $directory = storage_path('app/private/exports');

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $path = $directory.'/'.Str::slug(__('documents.trainings.export.file', ['date' => PersonDocument::today()->toDateString()])).'-'.Str::random(6).'.xlsx';
        $bold = (new Style)->setFontBold();
        $styles = collect(self::COLORS)->map(fn (string $color): Style => (new Style)->setBackgroundColor($color))->all();

        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName(mb_substr(__('documents.trainings.title'), 0, 31));

        $types = collect($matrix['types']);
        $writer->addRow(new Row(array_map(fn (string $title): Cell => Cell::fromValue($title, $bold), [
            __('documents.fields.person'), __('app.teams.team'), ...$types->map(fn (DocumentType $type): string => $type->label())->all(),
        ])));

        foreach ($matrix['rows'] as $row) {
            $cells = [Cell::fromValue($row['person']->name), Cell::fromValue($row['person']->team?->name)];

            foreach ($types as $type) {
                $cell = $row['cells'][$type->getKey()];
                $cells[] = Cell::fromValue(static::text($cell), $styles[$cell['status']] ?? null);
            }

            $writer->addRow(new Row($cells));
        }

        $writer->close();

        return $path;
    }

    /**
     * "Em dia até 10/03/2027", "Vencido (venceu 01/10/2026)", "Falta fazer" or empty.
     *
     * @param  array{status: string, document: ?PersonDocument, required: bool}  $cell
     */
    public static function text(array $cell): string
    {
        if ($cell['status'] === 'none') {
            return '';
        }

        $label = __('documents.trainings.status.'.$cell['status']);
        $document = $cell['document'];

        if ($document === null) {
            return $label;
        }

        $date = $document->expires_on?->translatedFormat(__('app.date_format'));

        return $label.' · '.match (true) {
            $date === null => __('documents.trainings.cell.no_expiry'),
            $cell['status'] === 'overdue' => __('documents.trainings.cell.expired_on', ['date' => $date]),
            default => __('documents.trainings.cell.until', ['date' => $date]),
        };
    }
}
