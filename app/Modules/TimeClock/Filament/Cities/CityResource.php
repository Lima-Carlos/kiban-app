<?php

namespace App\Modules\TimeClock\Filament\Cities;

use App\Filament\Support\ModuleResource;
use App\Modules\TimeClock\Models\City;
use App\Modules\TimeClock\Services\CityHolidays;
use App\Modules\TimeClock\TimeClockModule;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Cities of the company for the municipal holidays (managers). Search the city (IBGE list, Brazil) or type it;
 * "Suggest holidays" brings the municipal holidays of the open base, which the company then reviews.
 */
class CityResource extends ModuleResource
{
    protected static string $moduleKey = TimeClockModule::KEY;

    protected static ?string $model = City::class;

    protected static ?string $langGroup = 'timeclock.cities';

    protected static ?string $slug = 'ponto/cidades';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static ?int $navigationSort = 26;

    public static function getModelLabel(): string
    {
        return __('timeclock.cities.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('timeclock.cities.plural');
    }

    public static function getTitleCasePluralModelLabel(): string
    {
        return __('timeclock.cities.plural_title');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Select::make('ibge_code')
                    ->label(__('timeclock.cities.search'))
                    ->helperText(__('timeclock.cities.search_help'))
                    ->searchable()
                    ->live()
                    ->getSearchResultsUsing(fn (string $search): array => app(CityHolidays::class)->search($search))
                    ->getOptionLabelUsing(fn ($value): ?string => ($city = app(CityHolidays::class)->find((int) $value)) ? $city['name'].' - '.$city['uf'] : null)
                    ->afterStateUpdated(function ($state, Set $set): void {
                        $city = $state ? app(CityHolidays::class)->find((int) $state) : null;

                        if ($city) {
                            $set('name', $city['name']);
                            $set('state', $city['uf']);
                        }
                    }),
                TextInput::make('name')
                    ->label(__('timeclock.cities.name'))
                    ->required()
                    ->maxLength(120),
                TextInput::make('state')
                    ->label(__('timeclock.fields.state'))
                    ->maxLength(40),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label(__('timeclock.cities.name'))->weight('bold')->searchable(),
                TextColumn::make('state')->label(__('timeclock.fields.state')),
                TextColumn::make('holidays_count')->counts('holidays')->label(__('timeclock.cities.holidays_count')),
            ])
            ->recordActions([
                Action::make('suggest')
                    ->label(__('timeclock.cities.suggest'))
                    ->icon(Heroicon::OutlinedSparkles)
                    ->color('gray')
                    ->visible(fn (City $record): bool => (bool) $record->ibge_code)
                    ->authorize(fn (City $record): bool => auth()->user()?->can('update', $record) ?? false)
                    ->requiresConfirmation()
                    ->modalDescription(__('timeclock.cities.suggest_help'))
                    ->action(function (City $record): void {
                        $service = app(CityHolidays::class);
                        $added = $service->import($record, (int) now()->year);

                        if ($added === 0 && $service->suggestions($record, (int) now()->year) === []) {
                            Notification::make()->warning()->title(__('timeclock.cities.suggest_none'))->send();

                            return;
                        }

                        Notification::make()->success()->title(trans_choice('timeclock.cities.suggest_done', $added, ['count' => $added]))->send();
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading(__('timeclock.cities.empty'))
            ->emptyStateDescription(__('timeclock.cities.empty_help'));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageCities::route('/'),
        ];
    }
}
