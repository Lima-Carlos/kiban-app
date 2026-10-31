<?php

namespace App\People\Epi;

use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * The "PPE and tools" list in an Excel spreadsheet (OpenSpout, like the other spreadsheets): one line per item
 * with a person, dates as real dates and the cost as a number.
 */
class EpiExporter
{
    /**
     * Path of the .xlsx file (deleted after the download).
     *
     * @param  iterable<EpiDeliveryLine>  $lines
     */
    public function export(iterable $lines): string
    {
        $directory = storage_path('app/private/exports');

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $today = EpiItem::today();
        $path = $directory.'/'.Str::slug(__('epi.export.file', ['date' => $today->toDateString()])).'-'.Str::random(6).'.xlsx';
        $bold = (new Style)->setFontBold();
        $date = (new Style)->setFormat(str_starts_with(__('app.date_format'), 'm') ? 'mm/dd/yyyy' : 'dd/mm/yyyy');
        $money = (new Style)->setFormat('#,##0.00');
        $day = fn ($value): Cell => $value !== null ? Cell::fromValue(\DateTimeImmutable::createFromInterface($value), $date) : Cell::fromValue(null);

        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName(mb_substr(__('epi.title'), 0, 31));

        $writer->addRow(new Row(array_map(fn (string $title): Cell => Cell::fromValue($title, $bold), [
            __('epi.fields.person'), __('app.teams.team'), __('epi.fields.item'), __('epi.fields.kind'), __('epi.fields.size'),
            __('epi.fields.ca_short'), __('epi.fields.ca_expires_on'), __('epi.export.with_person'), __('epi.fields.delivered_on'),
            __('epi.fields.reason'), __('epi.fields.replace_on'), __('epi.fields.replacement'), __('epi.fields.signature'),
            __('epi.fields.unit_cost'),
        ])));

        foreach ($lines as $line) {
            $writer->addRow(new Row([
                Cell::fromValue($line->delivery->person->name),
                Cell::fromValue($line->delivery->person->team?->name),
                Cell::fromValue($line->item_name),
                Cell::fromValue($line->kind->getLabel()),
                Cell::fromValue($line->size),
                Cell::fromValue($line->ca_number),
                $day($line->ca_expires_on),
                Cell::fromValue($line->openQuantity()),
                $day($line->delivery->delivered_on),
                Cell::fromValue($line->delivery->reason->getLabel()),
                $day($line->replace_on),
                Cell::fromValue($line->statusText($today)),
                Cell::fromValue($line->delivery->isSigned() ? __('epi.sign.signed') : __('epi.sign.pending')),
                $line->unit_cost_cents !== null ? Cell::fromValue($line->unit_cost_cents / 100, $money) : Cell::fromValue(null),
            ]));
        }

        $writer->close();

        return $path;
    }
}
