<?php

namespace App\Modules\TimeClock\Services;

use App\Modules\TimeClock\Models\Adjustment;
use App\Modules\TimeClock\Models\Closing;
use App\Modules\TimeClock\Models\TimeClockSetting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * The month for the accountant (Excel): one sheet with the totals per employee (what the payroll needs) and one
 * with every day. Closed months use the frozen copy. Hours go as real Excel times ([h]:mm), so the spreadsheet
 * can add them up; the hour bank goes in decimal hours (it may be negative).
 */
class TimeClockExporter
{
    public function __construct(
        protected MonthClosing $closing,
        protected TimesheetDocument $document,
    ) {}

    /** Path of the .xlsx file (deleted after the download). */
    public function month(CarbonInterface $month): string
    {
        $start = CarbonImmutable::parse($month->format('Y-m-01'));
        $settings = TimeClockSetting::current();

        $summary = [[
            __('timeclock.fields.name'), __('timeclock.fields.registration'), __('timeclock.export.document'), __('timeclock.fields.pis'),
            __('timeclock.fields.job_title'), __('timeclock.fields.workplace'), __('timeclock.closing.status'),
            __('timeclock.timesheet.totals.expected'), __('timeclock.timesheet.totals.worked'),
            __('timeclock.export.extra', ['percent' => $settings->overtime_percent]),
            __('timeclock.export.extra_rest', ['percent' => $settings->rest_day_overtime_percent]),
            __('timeclock.timesheet.totals.deficit'), __('timeclock.timesheet.totals.absences'), __('timeclock.export.absence_hours'),
            __('timeclock.export.excused'), __('timeclock.export.night', ['percent' => $settings->night_percent]),
            __('timeclock.export.missing_punches'), __('timeclock.export.bank'), __('timeclock.export.bank_expired'),
        ]];
        $daily = [[
            __('timeclock.fields.name'), __('timeclock.fields.date'), __('timeclock.timesheet.expected'), __('timeclock.timesheet.punches'),
            __('timeclock.timesheet.worked'), __('timeclock.timesheet.extra'), __('timeclock.timesheet.totals.extra_rest'),
            __('timeclock.timesheet.short'), __('timeclock.timesheet.night'), __('timeclock.timesheet.notes'),
        ]];

        foreach ($this->closing->employeesOf($start) as $employee) {
            $data = $this->document->month($employee, $start);
            $totals = $data['totals'];
            $name = (string) $employee->person?->name;

            $summary[] = [
                $name, $employee->registration, $employee->person?->documentLabel(), $employee->pis, $employee->job_title, $employee->workplace?->name,
                __('timeclock.closing.statuses.'.($data['closing']?->status() ?? Closing::OPEN)),
                $this->time($totals['expected']), $this->time($totals['worked']), $this->time($totals['extra']), $this->time($totals['extra_rest']),
                $this->time($totals['deficit'] - $totals['absence_minutes']), $totals['absences'], $this->time($totals['absence_minutes']),
                $this->time($totals['excused']), $this->time($totals['night_reduced']), $totals['missing_punches'],
                $data['bank'] === null ? null : round($data['bank']['balance'] / 60, 2),
                $data['bank'] === null ? null : round($data['bank']['expired'] / 60, 2),
            ];

            foreach ($data['days'] as $day) {
                if (! $day['counts']) {
                    continue;
                }

                $daily[] = [
                    $name,
                    $day['date']->translatedFormat(__('app.date_format')),
                    $day['holiday'] ?? ($day['plan'] ? $day['plan']['start'].'–'.$day['plan']['end'] : __('timeclock.employees.day_off')),
                    collect($day['punches'])->map(fn (array $punch): string => $punch['at']->format('H:i').($punch['kind'] === 'added' ? ' (I)' : ''))->implode(' '),
                    $this->time($day['worked']), $this->time($day['extra']), $this->time($day['extra_rest']), $this->time($day['deficit']), $this->time($day['night_reduced']),
                    collect([
                        $day['absence'] ? __('timeclock.timesheet.flags.absence') : null,
                        ...array_map(fn (string $flag): string => __('timeclock.timesheet.flags.'.$flag), $day['flags']),
                        ...collect($day['adjustments'])->where('type', Adjustment::EXCUSE)->map(fn ($excuse): string => $excuse->minutes === null ? __('timeclock.timesheet.excused_day') : __('timeclock.timesheet.excused', ['hours' => ScheduleCalendar::hours($excuse->minutes)]))->all(),
                    ])->filter()->implode(' · '),
                ];
            }
        }

        return $this->write(__('timeclock.export.file', ['month' => $start->format('Y-m')]), [
            __('timeclock.export.summary') => $summary,
            __('timeclock.export.days') => $daily,
        ]);
    }

    /**
     * @return array{minutes: int}
     */
    protected function time(int $minutes): array
    {
        return ['minutes' => $minutes];
    }

    /**
     * @param  array<string, array<int, array<int, mixed>>>  $sheets  first row of each sheet = header
     */
    protected function write(string $fileName, array $sheets): string
    {
        $directory = storage_path('app/private/exports');

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $path = $directory.'/'.Str::slug($fileName).'-'.Str::random(6).'.xlsx';
        $bold = (new Style)->setFontBold();
        $time = (new Style)->setFormat('[h]:mm');
        $number = (new Style)->setFormat('0.00');

        $writer = new Writer;
        $writer->openToFile($path);
        $first = true;

        foreach ($sheets as $name => $rows) {
            $sheet = $first ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
            $sheet->setName(mb_substr(str_replace(['/', '\\', '?', '*', '[', ']', ':'], '-', $name), 0, 31));
            $first = false;

            foreach ($rows as $index => $values) {
                $cells = array_map(fn (mixed $value): Cell => match (true) {
                    is_array($value) => Cell::fromValue($value['minutes'] / 1440, $time),
                    is_float($value) => Cell::fromValue($value, $number),
                    default => Cell::fromValue($value, $index === 0 ? $bold : null),
                }, array_values($values));

                $writer->addRow(new Row($cells));
            }
        }

        $writer->close();

        return $path;
    }
}
