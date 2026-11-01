<?php

namespace App\Modules\Agenda\Filament;

use App\Connections\Bridges\AgendaField;
use App\Modules\Agenda\AgendaModule;
use App\Modules\Agenda\Models\AgendaSetting;
use App\Modules\Agenda\Services\AgendaAccess;
use App\Modules\Agenda\Services\AgendaTemplates;
use App\Modules\Concerns\InModuleMenu;
use App\Support\Country;
use App\Tenancy\TenantContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Agenda settings (manager): the business (brings the ready services), the currency, the public booking page
 * (on/off, step between times, notice, days ahead, until when the client changes by the link, message), the
 * pick-up and, with the Campo, the type of service of the pick-up work order.
 */
class AgendaSettingsPage extends Page
{
    use InModuleMenu;

    protected static string $moduleKey = AgendaModule::KEY;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?int $navigationSort = 90;

    protected static ?string $slug = 'agenda/configuracoes';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $tenant = app(TenantContext::class)->current();

        return $tenant !== null && $tenant->license()->canWrite(AgendaModule::KEY) && app(AgendaAccess::class)->isManager();
    }

    public static function getNavigationLabel(): string
    {
        return __('agenda.settings.title');
    }

    public function getTitle(): string
    {
        return __('agenda.settings.title');
    }

    public function mount(): void
    {
        $settings = AgendaSetting::current();
        $this->form->fill($settings->only(['business', 'currency', 'online_booking', 'slot_minutes', 'min_notice_minutes', 'booking_days', 'cancel_hours', 'no_show_flag', 'pickup_enabled', 'field_service_type_id', 'booking_message'])
            + ['business' => $settings->business ?? 'petshop']);
    }

    public function form(Schema $schema): Schema
    {
        $bridge = app(AgendaField::class);

        return $schema
            ->statePath('data')
            ->columns(1)
            ->components([
                Section::make(__('agenda.settings.business'))
                    ->description(__('agenda.settings.business_help'))
                    ->components([
                        Radio::make('business')
                            ->hiddenLabel()
                            ->options(app(AgendaTemplates::class)->businesses())
                            ->descriptions(collect(AgendaSetting::BUSINESSES)->mapWithKeys(fn (string $business): array => [$business => __('agenda.templates.'.$business.'.help')])->all())
                            ->required(),
                        Select::make('currency')
                            ->label(__('field.settings.currency'))
                            ->options(collect(Country::CURRENCIES)->unique()->mapWithKeys(fn (string $code): array => [$code => Country::currencyName($code)])->sort()->all())
                            ->searchable()
                            ->required(),
                    ]),
                Section::make(__('agenda.settings.online'))
                    ->description(__('agenda.settings.online_help'))
                    ->columns(['default' => 1, 'sm' => 2])
                    ->components([
                        Toggle::make('online_booking')->label(__('agenda.settings.online_booking'))->columnSpanFull(),
                        Text::make(fn (): string => __('agenda.settings.link', ['link' => route('agenda.booking')]))->columnSpanFull(),
                        Select::make('slot_minutes')->label(__('agenda.settings.slot_minutes'))
                            ->options([10 => '10 min', 15 => '15 min', 20 => '20 min', 30 => '30 min', 45 => '45 min', 60 => '1 h'])->required(),
                        TextInput::make('min_notice_minutes')->label(__('agenda.settings.min_notice'))->helperText(__('agenda.settings.min_notice_help'))
                            ->numeric()->integer()->minValue(0)->maxValue(10080)->suffix('min')->required(),
                        TextInput::make('booking_days')->label(__('agenda.settings.booking_days'))
                            ->numeric()->integer()->minValue(1)->maxValue(365)->suffix(__('agenda.fields.days'))->required(),
                        TextInput::make('cancel_hours')->label(__('agenda.settings.cancel_hours'))->helperText(__('agenda.settings.cancel_hours_help'))
                            ->numeric()->integer()->minValue(0)->maxValue(168)->suffix('h')->required(),
                        TextInput::make('no_show_flag')->label(__('agenda.settings.no_show_flag'))->helperText(__('agenda.settings.no_show_flag_help'))
                            ->numeric()->integer()->minValue(0)->maxValue(20)->required(),
                        Toggle::make('pickup_enabled')->label(__('agenda.settings.pickup_enabled'))->helperText(__('agenda.settings.pickup_enabled_help'))->inline(false),
                        Textarea::make('booking_message')->label(__('agenda.settings.booking_message'))->helperText(__('agenda.settings.booking_message_help'))->rows(2)->maxLength(500)->columnSpanFull(),
                    ]),
                Section::make(__('agenda.settings.field'))
                    ->description(__('agenda.settings.field_help'))
                    ->visible($bridge->enabled())
                    ->components([
                        Select::make('field_service_type_id')->label(__('agenda.settings.field_type'))->options(fn (): array => $bridge->serviceTypeOptions()),
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
                        Action::make('save')->label(__('agenda.settings.save'))->submit('save'),
                    ]),
                ]),
        ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $settings = AgendaSetting::current();
        $state = $this->form->getState();
        $settings->update($state + ['configured_at' => $settings->configured_at ?? now()]);

        $added = app(AgendaTemplates::class)->apply((string) $state['business']);

        Notification::make()
            ->success()
            ->title(__('agenda.settings.saved'))
            ->body($added['services'] > 0 ? __('agenda.settings.model_added', ['services' => $added['services']]) : null)
            ->send();
    }
}
