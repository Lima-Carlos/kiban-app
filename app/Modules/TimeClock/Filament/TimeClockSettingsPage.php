<?php

namespace App\Modules\TimeClock\Filament;

use App\Modules\ModuleRegistry;
use App\Modules\TimeClock\Enums\LaborProfile;
use App\Modules\TimeClock\Models\TimeClockSetting;
use App\Modules\TimeClock\Services\TimeClockAccess;
use App\Modules\TimeClock\TimeClockModule;
use App\Support\Country;
use App\Tenancy\TenantContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Locale;

/**
 * Time clock settings (managers): the company's country and the labor rule profile.
 * "Brazil (CLT)" follows the official rules; "Custom" is for other countries (the details of the rules
 * — workweek, overtime, night hours — come with the schedules and the calculation, parts 2 and 4).
 */
class TimeClockSettingsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?int $navigationSort = 90;

    protected static ?string $slug = 'ponto/configuracoes';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $tenant = app(TenantContext::class)->current();

        return $tenant !== null && $tenant->license()->canWrite(TimeClockModule::KEY) && app(TimeClockAccess::class)->isManager();
    }

    public static function getNavigationGroup(): ?string
    {
        return app(ModuleRegistry::class)->get(TimeClockModule::KEY)->name();
    }

    public static function getNavigationLabel(): string
    {
        return __('timeclock.settings.title');
    }

    public function getTitle(): string
    {
        return __('timeclock.settings.title');
    }

    public function mount(): void
    {
        $settings = TimeClockSetting::current();
        $this->form->fill(['country' => $settings->country, 'labor_profile' => $settings->labor_profile->value]);
    }

    /**
     * @return array<string, string>
     */
    public static function countries(): array
    {
        return collect(array_keys(Country::CALLING_CODES))
            ->mapWithKeys(fn (string $code): array => [$code => Locale::getDisplayRegion('-'.$code, app()->getLocale())])
            ->sort()
            ->all();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('timeclock.settings.rules'))
                    ->description(__('timeclock.settings.rules_help'))
                    ->components([
                        Select::make('country')
                            ->label(__('timeclock.settings.country'))
                            ->options(static::countries())
                            ->searchable()
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (?string $state, $set) => $state ? $set('labor_profile', LaborProfile::forCountry($state)->value) : null),
                        Radio::make('labor_profile')
                            ->label(__('timeclock.settings.labor_profile'))
                            ->options(collect(LaborProfile::cases())->mapWithKeys(fn (LaborProfile $profile): array => [$profile->value => $profile->getLabel()])->all())
                            ->descriptions(collect(LaborProfile::cases())->mapWithKeys(fn (LaborProfile $profile): array => [$profile->value => __('timeclock.enums.labor_profile_help.'.$profile->value)])->all())
                            ->required(),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')
                            ->label(__('timeclock.settings.save'))
                            ->submit('save'),
                    ]),
                ]),
        ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $state = $this->form->getState();
        $settings = TimeClockSetting::current();
        $settings->fill(['country' => $state['country'], 'labor_profile' => $state['labor_profile']]);
        $settings->forceFill(['configured_at' => $settings->configured_at ?? now()])->save();

        Notification::make()->success()->title(__('timeclock.settings.saved'))->send();
    }
}
