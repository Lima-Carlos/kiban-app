<?php

namespace App\Modules\Field\Filament\Customers\Pages;

use App\Modules\Field\Filament\Customers\CustomerResource;
use App\Modules\Field\Services\CustomerImporter;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * List of customers, with "Import spreadsheet" (and the model to download) for who registers customers.
 */
class ListCustomers extends ListRecords
{
    protected static string $resource = CustomerResource::class;

    /** Says why a customer cannot be added (read-only, no access to change). */
    public function getSubheading(): string|Htmlable|null
    {
        $response = CustomerResource::getCreateAuthorizationResponse();

        return $response->denied() && $response->message() !== __('field.denied.manager') ? $response->message() : null;
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            ActionGroup::make([
                Action::make('import')
                    ->label(__('field.import.action'))
                    ->icon(Heroicon::OutlinedArrowUpTray)
                    ->modalHeading(__('field.import.heading'))
                    ->modalDescription(__('field.import.help'))
                    ->modalSubmitActionLabel(__('field.import.submit'))
                    ->authorize(fn (): bool => CustomerResource::canCreate())
                    ->schema([
                        FileUpload::make('file')
                            ->label(__('field.import.file'))
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
                    ->action(fn (array $data) => $this->import((string) $data['file'])),
                Action::make('template')
                    ->label(__('field.import.template'))
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->authorize(fn (): bool => CustomerResource::canCreate())
                    ->action(fn (): BinaryFileResponse => response()->download(app(CustomerImporter::class)->template(), __('field.import.template_file').'.xlsx')->deleteFileAfterSend()),
            ])
                ->label(__('field.import.menu'))
                ->icon(Heroicon::OutlinedTableCells)
                ->button()
                ->color('gray'),
        ];
    }

    protected function import(string $file): void
    {
        $disk = Storage::disk('local');

        try {
            $result = app(CustomerImporter::class)->import($disk->path($file), pathinfo($file, PATHINFO_EXTENSION));
        } catch (\Throwable $exception) {
            report($exception);
            Notification::make()->danger()->title(__('field.import.unreadable'))->send();

            return;
        } finally {
            $disk->delete($file);
        }

        $errors = collect($result['errors']);

        Notification::make()
            ->title(__('field.import.done', ['created' => $result['created'], 'updated' => $result['updated']]))
            ->body($errors->isEmpty() ? null : trans_choice('field.import.errors', $errors->count(), ['count' => $errors->count()])."\n".$errors->take(10)->map(fn (array $error): string => __('field.import.line', ['line' => $error['line'], 'message' => $error['message']]))->implode("\n"))
            ->color($errors->isEmpty() ? 'success' : 'warning')
            ->icon($errors->isEmpty() ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedExclamationTriangle)
            ->persistent()
            ->send();
    }
}
