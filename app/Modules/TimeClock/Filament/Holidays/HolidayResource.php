<?php

namespace App\Modules\TimeClock\Filament\Holidays;

use App\Filament\Support\ModuleResource;
use App\Modules\TimeClock\Models\Holiday;
use App\Modules\TimeClock\Models\Workplace;
use App\Modules\TimeClock\TimeClockModule;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Holidays of the company (managers): state, city and its own, for everyone or one workplace, once or
 * every year. The national holidays (Brazil) are calculated and shown on top, nothing to register.
 */
class HolidayResource extends ModuleResource
{
    protected static string $moduleKey = TimeClockModule::KEY;

    protected static ?string $model = Holiday::class;

    protected static ?string $slug = 'ponto/feriados';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSun;

    protected static ?int $navigationSort = 25;

    public static function getModelLabel(): string
    {
        return __('timeclock.holidays.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('timeclock.holidays.plural');
    }

    public static function getTitleCasePluralModelLabel(): string
    {
        return __('timeclock.holidays.plural_title');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                DatePicker::make('date')
                    ->label(__('timeclock.fields.date'))
                    ->required(),
                TextInput::make('name')
                    ->label(__('timeclock.fields.name'))
                    ->placeholder(__('timeclock.holidays.name_placeholder'))
                    ->required()
                    ->maxLength(120),
                Toggle::make('recurring')
                    ->label(__('timeclock.holidays.recurring'))
                    ->helperText(__('timeclock.holidays.recurring_help')),
                Select::make('workplace_id')
                    ->label(__('timeclock.fields.workplace'))
                    ->placeholder(__('timeclock.holidays.whole_company'))
                    ->helperText(__('timeclock.holidays.workplace_help'))
                    ->options(fn (): array => Workplace::query()->orderBy('name')->pluck('name', 'id')->all()),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('date')
            ->columns([
                TextColumn::make('date')
                    ->label(__('timeclock.fields.date'))
                    ->state(fn (Holiday $record): string => $record->recurring ? $record->date->translatedFormat(__('timeclock.holidays.day_month_format')) : $record->date->translatedFormat(__('app.date_format')))
                    ->sortable(),
                TextColumn::make('name')
                    ->label(__('timeclock.fields.name'))
                    ->weight('bold')
                    ->searchable(),
                IconColumn::make('recurring')
                    ->label(__('timeclock.holidays.recurring_short'))
                    ->boolean(),
                TextColumn::make('workplace.name')
                    ->label(__('timeclock.fields.workplace'))
                    ->placeholder(__('timeclock.holidays.whole_company')),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading(__('timeclock.holidays.empty'))
            ->emptyStateDescription(__('timeclock.holidays.empty_help'));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageHolidays::route('/'),
        ];
    }
}
