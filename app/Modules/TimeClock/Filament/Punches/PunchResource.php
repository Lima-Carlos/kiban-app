<?php

namespace App\Modules\TimeClock\Filament\Punches;

use App\Filament\Support\ModuleResource;
use App\Modules\TimeClock\Models\Employee;
use App\Modules\TimeClock\Models\Punch;
use App\Modules\TimeClock\Services\ReceiptService;
use App\Modules\TimeClock\TimeClockModule;
use App\Support\Geo;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

/**
 * All punches (managers and viewers): who, when (local time), how (phone or tablet), the photo, the place and
 * the warnings. Read-only: punches never change (corrections come in part 4).
 */
class PunchResource extends ModuleResource
{
    protected static string $moduleKey = TimeClockModule::KEY;

    protected static ?string $model = Punch::class;

    protected static ?string $langGroup = 'timeclock.punches';

    protected static ?string $slug = 'ponto/batidas';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?int $navigationSort = 5;

    public static function getModelLabel(): string
    {
        return __('timeclock.punches.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('timeclock.punches.plural');
    }

    public static function getTitleCasePluralModelLabel(): string
    {
        return __('timeclock.punches.plural_title');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['employee.person', 'kiosk']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('punched_at', 'desc')
            ->columns([
                ImageColumn::make('photo_path')
                    ->label('')
                    ->disk('local')
                    ->visibility('private')
                    ->circular()
                    ->imageSize(40),
                TextColumn::make('punched_at')
                    ->label(__('timeclock.punches.when'))
                    ->state(fn (Punch $record): string => $record->localTime()->translatedFormat(__('app.date_format')).' '.$record->localTime()->format('H:i'))
                    ->description(fn (Punch $record): string => '#'.$record->nsr)
                    ->sortable(),
                TextColumn::make('employee.person.name')
                    ->label(__('timeclock.fields.name'))
                    ->weight('bold')
                    ->searchable(),
                TextColumn::make('source')
                    ->label(__('timeclock.receipt.how'))
                    ->formatStateUsing(fn (string $state, Punch $record): string => __('timeclock.punches.sources.'.$state).($record->kiosk ? ' · '.$record->kiosk->name : ''))
                    ->visibleFrom('md'),
                TextColumn::make('distance_meters')
                    ->label(__('timeclock.punches.place'))
                    ->state(fn (Punch $record): ?string => $record->latitude === null ? null : ($record->distance_meters !== null ? __('timeclock.helpers.meters', ['meters' => number_format($record->distance_meters, 0, ',', '.')]) : __('timeclock.punches.see_map')))
                    ->placeholder('—')
                    ->url(fn (Punch $record): ?string => $record->latitude !== null ? Geo::mapUrl($record->latitude, $record->longitude) : null, shouldOpenInNewTab: true)
                    ->color('primary')
                    ->visibleFrom('md'),
                TextColumn::make('flags')
                    ->label(__('timeclock.punches.warnings'))
                    ->badge()
                    ->color('warning')
                    ->formatStateUsing(fn (string $state): string => __('timeclock.punches.flags.'.$state))
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('employee_id')
                    ->label(__('timeclock.employees.singular_title'))
                    ->options(fn (): array => Employee::query()->with('person')->get()->mapWithKeys(fn (Employee $employee): array => [$employee->getKey() => $employee->person?->name])->sort()->all())
                    ->searchable(),
                Filter::make('period')
                    ->schema([
                        DatePicker::make('from')->label(__('timeclock.punches.from')),
                        DatePicker::make('to')->label(__('timeclock.punches.to')),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $from) => $query->where('punched_at', '>=', now()->parse($from)->startOfDay()))
                        ->when($data['to'] ?? null, fn (Builder $query, string $to) => $query->where('punched_at', '<=', now()->parse($to)->endOfDay()))),
                Filter::make('with_warnings')
                    ->label(__('timeclock.punches.with_warnings'))
                    ->query(fn (Builder $query) => $query->whereRaw("flags::text <> '[]'")),
            ])
            ->recordActions([
                Action::make('photo')
                    ->label(__('timeclock.punches.photo'))
                    ->icon(Heroicon::OutlinedPhoto)
                    ->link()
                    ->visible(fn (Punch $record): bool => filled($record->photo_path))
                    ->url(fn (Punch $record): string => Storage::disk('local')->temporaryUrl($record->photo_path, now()->addMinutes(10)), shouldOpenInNewTab: true),
                Action::make('receipt')
                    ->label(__('timeclock.receipt.short'))
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->link()
                    ->url(fn (Punch $record): string => app(ReceiptService::class)->url($record), shouldOpenInNewTab: true),
            ])
            ->emptyStateHeading(__('timeclock.punches.none'));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPunches::route('/'),
        ];
    }
}
