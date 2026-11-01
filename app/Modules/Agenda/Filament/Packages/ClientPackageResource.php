<?php

namespace App\Modules\Agenda\Filament\Packages;

use App\Filament\Support\ModuleResource;
use App\Modules\Agenda\AgendaModule;
use App\Modules\Agenda\Models\Client;
use App\Modules\Agenda\Models\ClientPackage;
use App\Modules\Agenda\Models\Item;
use App\Modules\Agenda\Models\Package;
use App\Modules\Agenda\Services\AgendaFormat;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Packages sold to the clients (manager and front desk): sell one (client, pet, package) and follow the sessions
 * used and the expiry. The appointments use them by themselves (PackageService).
 */
class ClientPackageResource extends ModuleResource
{
    protected static string $moduleKey = AgendaModule::KEY;

    protected static ?string $model = ClientPackage::class;

    protected static ?string $langGroup = 'agenda.client_packages';

    protected static ?string $slug = 'agenda/pacotes-vendidos';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static ?int $navigationSort = 55;

    public static function getModelLabel(): string
    {
        return __('agenda.client_packages.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('agenda.client_packages.plural');
    }

    public static function getTitleCasePluralModelLabel(): string
    {
        return __('agenda.client_packages.plural_title');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Select::make('customer_id')->label(__('agenda.fields.client'))
                ->options(fn (): array => Client::query()->active()->orderBy('name')->limit(500)->pluck('name', 'id')->all())
                ->default(fn (): ?int => request()->integer('cliente') ?: null)
                ->searchable()->live()->required(),
            Select::make('item_id')->label(__('agenda.fields.item'))
                ->helperText(__('agenda.helpers.package_item'))
                ->options(fn (Get $get): array => Item::query()->active()->where('customer_id', $get('customer_id'))->orderBy('name')->pluck('name', 'id')->all()),
            Select::make('package_id')->label(__('agenda.packages.singular_title'))
                ->options(fn (): array => Package::query()->where('is_active', true)->orderBy('name')->get()->mapWithKeys(fn (Package $package): array => [$package->getKey() => $package->name.' · '.AgendaFormat::money($package->price_cents)])->all())
                ->required(),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['client', 'item']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('expires_on')
            ->columns([
                TextColumn::make('client.name')->label(__('agenda.fields.client'))->weight('bold')
                    ->description(fn (ClientPackage $record): ?string => $record->item?->name)->searchable(),
                TextColumn::make('name')->label(__('agenda.packages.singular_title')),
                TextColumn::make('sessions')->label(__('agenda.client_packages.sessions'))
                    ->state(fn (ClientPackage $record): string => __('agenda.client_packages.sessions_state', ['used' => $record->used(), 'taken' => $record->taken(), 'total' => $record->quantity]))
                    ->badge()->color(fn (ClientPackage $record): string => $record->left() > 0 ? 'info' : 'gray'),
                TextColumn::make('expires_on')->label(__('agenda.fields.expires_on'))->date(__('app.date_format'))
                    ->color(fn (ClientPackage $record): ?string => $record->isExpired() ? 'danger' : null)->sortable(),
                TextColumn::make('price_cents')->label(__('agenda.fields.price'))->formatStateUsing(fn (?int $state): string => AgendaFormat::money($state))->placeholder('—')->alignEnd()->visibleFrom('md'),
            ])
            ->filters([
                TernaryFilter::make('valid')->label(__('agenda.client_packages.valid'))
                    ->queries(true: fn (Builder $query) => $query->valid(), false: fn (Builder $query) => $query->where('expires_on', '<', now()->toDateString()))
                    ->default(true),
            ])
            ->recordActions([DeleteAction::make()])
            ->emptyStateHeading(__('agenda.client_packages.empty'))
            ->emptyStateDescription(__('agenda.client_packages.empty_help'));
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageClientPackages::route('/')];
    }
}
