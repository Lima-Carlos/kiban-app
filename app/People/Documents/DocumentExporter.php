<?php

namespace App\People\Documents;

use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * The "Expiry dates" list in an Excel spreadsheet (OpenSpout, like the other spreadsheets): one line per
 * document, dates as real dates (the spreadsheet can sort and filter them). No files.
 */
class DocumentExporter
{
    /**
     * Path of the .xlsx file (deleted after the download).
     *
     * @param  iterable<PersonDocument>  $documents
     */
    public function export(iterable $documents): string
    {
        $directory = storage_path('app/private/exports');

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $today = PersonDocument::today();
        $path = $directory.'/'.Str::slug(__('documents.export.file', ['date' => $today->toDateString()])).'-'.Str::random(6).'.xlsx';
        $bold = (new Style)->setFontBold();
        $date = (new Style)->setFormat(str_starts_with(__('app.date_format'), 'm') ? 'mm/dd/yyyy' : 'dd/mm/yyyy');

        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName(mb_substr(__('documents.expiry.title'), 0, 31));

        $writer->addRow(new Row(array_map(fn (string $title): Cell => Cell::fromValue($title, $bold), [
            __('documents.fields.person'), __('app.teams.team'), __('documents.fields.type'), __('documents.fields.number'),
            __('documents.fields.issued_on'), __('documents.fields.expires_on'), __('documents.fields.situation'),
            __('documents.export.days_left'), __('documents.types.is_health_short'), __('documents.fields.notes'),
        ])));

        foreach ($documents as $document) {
            $writer->addRow(new Row([
                Cell::fromValue($document->person->name),
                Cell::fromValue($document->person->team?->name),
                Cell::fromValue($document->type->label()),
                Cell::fromValue($document->number),
                $document->issued_on ? Cell::fromValue(\DateTimeImmutable::createFromInterface($document->issued_on), $date) : Cell::fromValue(null),
                $document->expires_on ? Cell::fromValue(\DateTimeImmutable::createFromInterface($document->expires_on), $date) : Cell::fromValue(null),
                Cell::fromValue($document->isCurrent() ? $document->statusText($today) : __('documents.status.replaced')),
                Cell::fromValue($document->daysLeft($today)),
                Cell::fromValue($document->type->is_health ? __('documents.export.yes') : __('documents.export.no')),
                Cell::fromValue($document->notes),
            ]));
        }

        $writer->close();

        return $path;
    }
}
