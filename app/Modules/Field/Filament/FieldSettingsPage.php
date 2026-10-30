<?php

namespace App\Modules\Field\Filament;

use App\Modules\Concerns\InModuleMenu;
use App\Modules\Field\FieldModule;
use App\Modules\Field\Models\FieldSetting;
use App\Modules\Field\Services\FieldAccess;
use App\Modules\Field\Services\FieldTemplates;
use App\Support\Country;
use App\Tenancy\TenantContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Kiban Campo settings (manager): the company's business, which brings the ready model of types of service
 * (with the technician's checklist) and materials; the currency of the prices; the customer's signature by
 * default. Choosing another business later only adds what is missing.
 */
class FieldSettingsPage extends Page
{
    use InModuleMenu;

    protected static string $moduleKey = FieldModule::KEY;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?int $navigationSort = 90;

    protected static ?string $slug = 'campo/configuracoes';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $tenant = app(TenantContext::class)->current();

        return $tenant !== null && $tenant->license()->canWrite(FieldModule::KEY) && app(FieldAccess::class)->isManager();
    }

    public static function getNavigationLabel(): string
    {
        return __('field.settings.title');
    }

    public function getTitle(): string
    {
        return __('field.settings.title');
    }

    public function mount(): void
    {
        $this->form->fill(FieldSetting::current()->only(['business', 'currency', 'signature_required']));
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->columns(1)
            ->components([
                Section::make(__('field.settings.business'))
                    ->description(__('field.settings.business_help'))
                    ->components([
                        Radio::make('business')
                            ->hiddenLabel()
                            ->options(app(FieldTemplates::class)->businesses())
                            ->descriptions(collect(FieldSetting::BUSINESSES)->mapWithKeys(fn (string $business): array => [$business => __('field.templates.'.$business.'.help')])->all())
                            ->required(),
                    ]),
                Section::make(__('field.settings.work'))
                    ->columns(['default' => 1, 'sm' => 2])
                    ->components([
                        Select::make('currency')
                            ->label(__('field.settings.currency'))
                            ->helperText(__('field.settings.currency_help'))
                            ->options(collect(Country::CURRENCIES)->unique()->sort()->mapWithKeys(fn (string $code): array => [$code => $code])->all())
                            ->searchable()
                            ->required(),
                        Toggle::make('signature_required')
                            ->label(__('field.fields.signature_required'))
                            ->helperText(__('field.settings.signature_help'))
                            ->inline(false),
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
                            ->label(__('field.settings.save'))
                            ->submit('save'),
                    ]),
                ]),
        ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $settings = FieldSetting::current();
        $state = $this->form->getState();
        $settings->update($state + ['configured_at' => $settings->configured_at ?? now()]);

        $added = app(FieldTemplates::class)->apply((string) $state['business']);

        Notification::make()
            ->success()
            ->title(__('field.settings.saved'))
            ->body($added['types'] + $added['materials'] > 0 ? __('field.settings.model_added', ['types' => $added['types'], 'materials' => $added['materials']]) : null)
            ->send();
    }
}
