<?php

namespace App\Modules\TimeClock\Filament\Punches\Pages;

use App\Modules\TimeClock\Afd\AfdImport;
use App\Modules\TimeClock\Afd\AfdImportException;
use App\Modules\TimeClock\Filament\Punches\PunchResource;
use App\Modules\TimeClock\Models\TimeClockSetting;
use App\Modules\TimeClock\Services\TimeClockAccess;
use App\Modules\TimeClock\TimeClockModule;
use App\Tenancy\TenantContext;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ListPunches extends ListRecords
{
    protected static string $resource = PunchResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return __('timeclock.punches.help');
    }

    protected function getHeaderActions(): array
    {
        return [
            // Brazil: the punches of a time clock the company already has, by its AFD file (any brand).
            Action::make('importAfd')
                ->label(__('timeclock.import.action'))
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->color('gray')
                ->visible(fn (): bool => app(TimeClockAccess::class)->isManager()
                    && app(TenantContext::class)->get()->license()->canWrite(TimeClockModule::KEY)
                    && TimeClockSetting::current()->isBrazil())
                ->modalHeading(__('timeclock.import.title'))
                ->modalDescription(__('timeclock.import.help'))
                ->modalSubmitActionLabel(__('timeclock.import.submit'))
                ->schema([
                    FileUpload::make('file')->label(__('timeclock.import.file'))->maxSize(20480)->storeFiles(false)->required(),
                    TextInput::make('name')->label(__('timeclock.import.name'))->helperText(__('timeclock.import.name_help'))->maxLength(80),
                ])
                ->action(function (array $data, Action $action): void {
                    $file = $data['file'];
                    $contents = $file instanceof TemporaryUploadedFile ? (string) file_get_contents($file->getRealPath()) : (string) Storage::get((string) $file);

                    try {
                        $result = app(AfdImport::class)->import($contents, $data['name'] ?? null);
                    } catch (AfdImportException $exception) {
                        Notification::make()->danger()->title($exception->getMessage())->send();
                        $action->halt();

                        return;
                    }

                    $details = array_filter([
                        $result['duplicates'] > 0 ? trans_choice('timeclock.import.duplicates', $result['duplicates'], ['count' => $result['duplicates']]) : null,
                        $result['closed'] > 0 ? trans_choice('timeclock.import.closed', $result['closed'], ['count' => $result['closed']]) : null,
                        $result['unknown'] !== [] ? __('timeclock.import.unknown', ['list' => implode(', ', array_slice($result['unknown'], 0, 10))]) : null,
                    ]);

                    Notification::make()
                        ->{$result['unknown'] === [] ? 'success' : 'warning'}()
                        ->title(trans_choice('timeclock.import.done', $result['imported'], ['count' => $result['imported'], 'clock' => $result['clock']->name]))
                        ->body(implode("\n", $details) ?: null)
                        ->persistent($result['unknown'] !== [])
                        ->send();
                }),
        ];
    }
}
