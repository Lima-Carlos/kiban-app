<?php

namespace App\Modules\TimeClock\Filament;

use App\Modules\Concerns\InModuleMenu;
use App\Modules\TimeClock\Enums\LaborProfile;
use App\Modules\TimeClock\Models\TimeClockSetting;
use App\Modules\TimeClock\Services\HolidayCalendar;
use App\Modules\TimeClock\Services\TimeClockAccess;
use App\Modules\TimeClock\TimeClockModule;
use App\Rules\Cnpj;
use App\Rules\Cpf;
use App\Support\Country;
use App\Tenancy\TenantContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
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
    use InModuleMenu;

    protected static string $moduleKey = TimeClockModule::KEY;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?int $navigationSort = 90;

    protected static ?string $slug = 'ponto/configuracoes';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** Calculation rules on this page (used from part 4 on). */
    public const RULE_FIELDS = [
        'overtime_percent', 'rest_day_overtime_percent', 'night_start', 'night_end', 'night_percent', 'night_reduced_hour',
        'tolerance_punch_minutes', 'tolerance_day_minutes', 'hour_bank', 'hour_bank_months',
    ];

    public static function canAccess(): bool
    {
        $tenant = app(TenantContext::class)->current();

        return $tenant !== null && $tenant->license()->canWrite(TimeClockModule::KEY) && app(TimeClockAccess::class)->isManager();
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
        $this->form->fill($settings->only(static::RULE_FIELDS) + [
            'country' => $settings->country,
            'labor_profile' => $settings->labor_profile->value,
            'optional_holidays' => collect(HolidayCalendar::OPTIONAL)->map(fn (bool $default, string $key): bool => app(HolidayCalendar::class)->optionalIsOn($key, $settings))->all(),
            'employer_document_type' => $settings->employer_document_type ?? 'cnpj',
            'employer_document' => $settings->employer_document,
            'employer_name' => $settings->employer_name,
            'allow_punch_without_photo' => (bool) $settings->allow_punch_without_photo,
            'privacy_contact' => $settings->privacy_contact,
        ]);
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
                            ->required()
                            ->live(),
                    ]),
                Section::make(__('timeclock.settings.calculation'))
                    ->description(fn (Get $get): string => $this->isBrazil($get) ? __('timeclock.settings.calculation_help_br') : __('timeclock.settings.calculation_help'))
                    ->columns(['default' => 1, 'sm' => 2])
                    ->components([
                        $this->percent('overtime_percent', 'overtime_percent'),
                        $this->percent('rest_day_overtime_percent', 'rest_day_overtime_percent'),
                        TimePicker::make('night_start')->label(__('timeclock.settings.night_start'))->seconds(false)->format('H:i')->timezone(config('app.timezone'))->required()->disabled(fn (Get $get): bool => $this->isBrazil($get)),
                        TimePicker::make('night_end')->label(__('timeclock.settings.night_end'))->seconds(false)->format('H:i')->timezone(config('app.timezone'))->required()->disabled(fn (Get $get): bool => $this->isBrazil($get)),
                        $this->percent('night_percent', 'night_percent'),
                        Toggle::make('night_reduced_hour')
                            ->label(__('timeclock.settings.night_reduced_hour'))
                            ->helperText(__('timeclock.settings.night_reduced_hour_help'))
                            ->disabled(fn (Get $get): bool => $this->isBrazil($get)),
                        TextInput::make('tolerance_punch_minutes')
                            ->label(__('timeclock.settings.tolerance_punch'))
                            ->integer()->minValue(0)->suffix('min')->required()
                            ->maxValue(fn (Get $get): int => $this->isBrazil($get) ? TimeClockSetting::LEGAL['tolerance_punch_minutes'] : 60),
                        TextInput::make('tolerance_day_minutes')
                            ->label(__('timeclock.settings.tolerance_day'))
                            ->integer()->minValue(0)->suffix('min')->required()
                            ->maxValue(fn (Get $get): int => $this->isBrazil($get) ? TimeClockSetting::LEGAL['tolerance_day_minutes'] : 120),
                        Toggle::make('hour_bank')
                            ->label(__('timeclock.settings.hour_bank'))
                            ->helperText(__('timeclock.settings.hour_bank_help'))
                            ->live(),
                        Select::make('hour_bank_months')
                            ->label(__('timeclock.settings.hour_bank_months'))
                            ->options(fn (Get $get): array => collect($this->isBrazil($get) ? [6, 12] : range(1, 12))->mapWithKeys(fn (int $months): array => [$months => trans_choice('timeclock.settings.months', $months, ['count' => $months])])->all())
                            ->helperText(fn (Get $get): ?string => $this->isBrazil($get) ? __('timeclock.settings.hour_bank_months_help_br') : null)
                            ->visible(fn (Get $get): bool => (bool) $get('hour_bank'))
                            ->required(),
                    ]),
                Section::make(__('timeclock.settings.optional_holidays'))
                    ->description(__('timeclock.settings.optional_holidays_help'))
                    ->visible(fn (Get $get): bool => strtoupper((string) $get('country')) === 'BR')
                    ->columns(['default' => 1, 'sm' => 3])
                    ->components(collect(array_keys(HolidayCalendar::OPTIONAL))->map(fn (string $key) => Toggle::make('optional_holidays.'.$key)->label(__('timeclock.holidays.optional.'.$key)))->all()),
                Section::make(__('timeclock.settings.photos'))
                    ->description(__('timeclock.settings.photos_help'))
                    ->columns(['default' => 1, 'sm' => 2])
                    ->components([
                        Toggle::make('allow_punch_without_photo')
                            ->label(__('timeclock.settings.allow_without_photo'))
                            ->helperText(__('timeclock.settings.allow_without_photo_help')),
                        TextInput::make('privacy_contact')
                            ->label(__('timeclock.settings.privacy_contact'))
                            ->placeholder(__('timeclock.settings.privacy_contact_placeholder'))
                            ->helperText(__('timeclock.settings.privacy_contact_help'))
                            ->maxLength(150),
                    ]),
                Section::make(__('timeclock.settings.employer'))
                    ->description(__('timeclock.settings.employer_help'))
                    ->visible(fn (Get $get): bool => $this->isBrazil($get))
                    ->columns(['default' => 1, 'sm' => 3])
                    ->components([
                        Radio::make('employer_document_type')
                            ->label(__('timeclock.settings.employer_document_type'))
                            ->options(['cnpj' => 'CNPJ', 'cpf' => 'CPF'])
                            ->inline()
                            ->live(),
                        TextInput::make('employer_document')
                            ->label(fn (Get $get): string => $get('employer_document_type') === 'cpf' ? 'CPF' : 'CNPJ')
                            ->rule(fn (Get $get) => $get('employer_document_type') === 'cpf' ? new Cpf : new Cnpj)
                            ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? preg_replace('/\D/', '', $state) : null),
                        TextInput::make('employer_name')
                            ->label(__('timeclock.settings.employer_name'))
                            ->maxLength(150),
                    ]),
            ]);
    }

    /** A percentage; in Brazil (CLT) it cannot be below the law. */
    protected function percent(string $field, string $label): TextInput
    {
        return TextInput::make($field)
            ->label(__('timeclock.settings.'.$label))
            ->integer()
            ->suffix('%')
            ->required()
            ->maxValue(400)
            ->minValue(fn (Get $get): int => $this->isBrazil($get) ? TimeClockSetting::LEGAL[$field] : 0)
            ->helperText(fn (Get $get): ?string => $this->isBrazil($get) ? __('timeclock.settings.legal_minimum', ['value' => TimeClockSetting::LEGAL[$field]]) : null);
    }

    protected function isBrazil(Get $get): bool
    {
        return $get('labor_profile') === LaborProfile::BrazilClt->value;
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
        $rules = collect($state)->only(static::RULE_FIELDS)->all();

        // Brazil (CLT): night hours and the reduced hour are the law's.
        if ($state['labor_profile'] === LaborProfile::BrazilClt->value) {
            $rules = ['night_start' => TimeClockSetting::LEGAL['night_start'], 'night_end' => TimeClockSetting::LEGAL['night_end'], 'night_reduced_hour' => true] + $rules;
        }

        $settings->fill($rules + [
            'country' => $state['country'],
            'labor_profile' => $state['labor_profile'],
            'optional_holidays' => array_map('boolval', (array) ($state['optional_holidays'] ?? [])) + (array) $settings->optional_holidays,
        ]);

        // Photos and privacy (a new contact changes the notice: everyone reads it again).
        $settings->forceFill([
            'allow_punch_without_photo' => (bool) ($state['allow_punch_without_photo'] ?? true),
            'privacy_contact' => filled($state['privacy_contact'] ?? null) ? trim((string) $state['privacy_contact']) : null,
        ]);

        if ($state['labor_profile'] === LaborProfile::BrazilClt->value) {
            $settings->forceFill([
                'employer_document_type' => filled($state['employer_document'] ?? null) ? ($state['employer_document_type'] ?? 'cnpj') : null,
                'employer_document' => $state['employer_document'] ?? null,
                'employer_name' => filled($state['employer_name'] ?? null) ? trim((string) $state['employer_name']) : null,
            ]);
        }
        $settings->forceFill(['configured_at' => $settings->configured_at ?? now()])->save();

        Notification::make()->success()->title(__('timeclock.settings.saved'))->send();
    }
}
