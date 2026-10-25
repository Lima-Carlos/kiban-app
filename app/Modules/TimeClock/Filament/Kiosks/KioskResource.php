<?php

namespace App\Modules\TimeClock\Filament\Kiosks;

use App\Filament\Support\ModuleResource;
use App\Modules\TimeClock\Models\Kiosk;
use App\Modules\TimeClock\Models\Workplace;
use App\Modules\TimeClock\Services\KioskService;
use App\Modules\TimeClock\TimeClockModule;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;

/**
 * Time clock tablets (managers). "Use this device as the time clock" is done on the tablet itself: it keeps a
 * secret and the manager is signed out of it (it never stays open with a login). "Deactivate" stops a lost or
 * replaced tablet at once.
 */
class KioskResource extends ModuleResource
{
    protected static string $moduleKey = TimeClockModule::KEY;

    protected static ?string $model = Kiosk::class;

    protected static ?string $slug = 'ponto/relogios';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDeviceTablet;

    protected static ?int $navigationSort = 30;

    public static function getModelLabel(): string
    {
        return __('timeclock.kiosks.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('timeclock.kiosks.plural');
    }

    public static function getTitleCasePluralModelLabel(): string
    {
        return __('timeclock.kiosks.plural_title');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TextInput::make('name')
                    ->label(__('timeclock.fields.name'))
                    ->placeholder(__('timeclock.kiosks.name_placeholder'))
                    ->required()
                    ->maxLength(120),
                Select::make('workplace_id')
                    ->label(__('timeclock.fields.workplace'))
                    ->helperText(__('timeclock.kiosks.workplace_help'))
                    ->options(fn (): array => Workplace::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('timeclock.fields.name'))
                    ->weight('bold')
                    ->description(fn (Kiosk $record): ?string => $record->workplace?->name),
                TextColumn::make('status')
                    ->label(__('timeclock.kiosks.status'))
                    ->state(fn (Kiosk $record): string => $record->isActivated() && $record->is_active ? 'on' : 'off')
                    ->formatStateUsing(fn (string $state): string => __('timeclock.kiosks.statuses.'.$state))
                    ->badge()
                    ->color(fn (string $state): string => $state === 'on' ? 'success' : 'gray'),
                TextColumn::make('last_seen_at')
                    ->label(__('timeclock.kiosks.last_seen'))
                    ->since()
                    ->placeholder('—'),
            ])
            ->recordActions([
                Action::make('activate')
                    ->label(__('timeclock.kiosks.activate'))
                    ->icon(Heroicon::OutlinedDeviceTablet)
                    ->color('success')
                    ->authorize('update')
                    ->requiresConfirmation()
                    ->modalHeading(__('timeclock.kiosks.activate'))
                    ->modalDescription(__('timeclock.kiosks.activate_help'))
                    ->action(function (Kiosk $record, $livewire): void {
                        $secret = app(KioskService::class)->activate($record);
                        Cookie::queue(Cookie::make(KioskService::COOKIE, $secret, KioskService::COOKIE_MINUTES, secure: request()->isSecure(), httpOnly: true, sameSite: 'lax'));

                        // The tablet never stays open with the manager's login.
                        Auth::guard('web')->logout();
                        session()->invalidate();
                        session()->regenerateToken();

                        $livewire->redirect(url('/relogio'));
                    }),
                Action::make('deactivate')
                    ->label(__('timeclock.kiosks.deactivate'))
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->iconButton()
                    ->visible(fn (Kiosk $record): bool => $record->isActivated())
                    ->authorize('update')
                    ->requiresConfirmation()
                    ->modalDescription(__('timeclock.kiosks.deactivate_help'))
                    ->action(function (Kiosk $record): void {
                        app(KioskService::class)->deactivate($record);
                        Notification::make()->success()->title(__('timeclock.kiosks.deactivated'))->send();
                    }),
                EditAction::make(),
            ])
            ->emptyStateHeading(__('timeclock.kiosks.empty'))
            ->emptyStateDescription(__('timeclock.kiosks.empty_help'));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageKiosks::route('/'),
        ];
    }
}
