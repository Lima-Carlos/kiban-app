<?php

namespace App\Filament\Support;

use App\Support\Spreadsheet\SpreadsheetImporter;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * The "Planilha" button of a list: import a spreadsheet and download the model (SpreadsheetImporter), with the
 * result in a notification (created, updated and the lines skipped with the reason).
 */
class ImportActions
{
    /**
     * @param  class-string<SpreadsheetImporter>  $importer
     * @param  string  $prefix  translations of the page: "<prefix>.help" and "<prefix>.template_file"
     */
    public static function make(string $importer, string $prefix, Closure $authorize): ActionGroup
    {
        return ActionGroup::make([
            Action::make('importSpreadsheet')
                ->label(__('app.import.action'))
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->modalHeading(__('app.import.action'))
                ->modalDescription(__($prefix.'.help'))
                ->modalSubmitActionLabel(__('app.import.submit'))
                ->authorize($authorize)
                ->schema([
                    FileUpload::make('file')
                        ->label(__('app.import.file'))
                        ->disk('local')
                        ->visibility('private')
                        ->directory('imports')
                        ->acceptedFileTypes([
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel',
                        ])
                        ->maxSize(10240)
                        ->required(),
                ])
                ->action(fn (array $data) => static::run($importer, (string) $data['file'])),
            Action::make('importTemplate')
                ->label(__('app.import.template'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->authorize($authorize)
                ->action(fn (): BinaryFileResponse => response()->download(app($importer)->template(), __($prefix.'.template_file').'.xlsx')->deleteFileAfterSend()),
        ])
            ->label(__('app.import.menu'))
            ->icon(Heroicon::OutlinedTableCells)
            ->button()
            ->color('gray');
    }

    /** @param  class-string<SpreadsheetImporter>  $importer */
    public static function run(string $importer, string $file): void
    {
        $disk = Storage::disk('local');

        try {
            $result = app($importer)->import($disk->path($file), pathinfo($file, PATHINFO_EXTENSION));
        } catch (Throwable $exception) {
            report($exception);
            Notification::make()->danger()->title(__('app.import.unreadable'))->send();

            return;
        } finally {
            $disk->delete($file);
        }

        $errors = collect($result['errors']);

        Notification::make()
            ->title(__('app.import.done', ['created' => $result['created'], 'updated' => $result['updated']]))
            ->body($errors->isEmpty() ? null : trans_choice('app.import.errors', $errors->count(), ['count' => $errors->count()])."\n".$errors->take(10)->map(fn (array $error): string => __('app.import.line', ['line' => $error['line'], 'message' => $error['message']]))->implode("\n"))
            ->color($errors->isEmpty() ? 'success' : 'warning')
            ->icon($errors->isEmpty() ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedExclamationTriangle)
            ->persistent()
            ->send();
    }
}
