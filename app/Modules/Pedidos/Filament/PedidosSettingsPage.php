<?php

namespace App\Modules\Pedidos\Filament;

use App\Filament\Support\MapPoint;
use App\Modules\Concerns\InModuleMenu;
use App\Modules\Pedidos\Models\PedidosSetting;
use App\Modules\Pedidos\PedidosModule;
use App\Modules\Pedidos\Services\PedidosAccess;
use App\Tenancy\TenantContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Pedidos settings (manager): the shop's address and point on the map (where the deliveries leave from), the city
 * filled in on new addresses, the suggested delivery fee, the "come back" message and how the rhythm is read.
 */
class PedidosSettingsPage extends Page
{
    use InModuleMenu;

    protected static string $moduleKey = PedidosModule::KEY;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?int $navigationSort = 90;

    protected static ?string $slug = 'pedidos/configuracoes';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $tenant = app(TenantContext::class)->current();

        return $tenant !== null && $tenant->license()->canWrite(PedidosModule::KEY) && app(PedidosAccess::class)->isManager();
    }

    public static function getNavigationLabel(): string
    {
        return __('pedidos.settings.title');
    }

    public function getTitle(): string
    {
        return __('pedidos.settings.title');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $settings = PedidosSetting::current();
        $this->form->fill($settings->only(['address', 'city', 'state', 'latitude', 'longitude', 'delivery_fee_cents', 'promo_message', 'late_percent', 'stopped_percent', 'min_orders'])
            + ['promo_message' => $settings->promoMessage()]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->columns(1)
            ->components([
                Section::make(__('pedidos.settings.shop'))
                    ->description(__('pedidos.settings.shop_help'))
                    ->columns(['default' => 1, 'sm' => 2])
                    ->components([
                        TextInput::make('address')->label(__('pedidos.settings.address'))->maxLength(255)->columnSpanFull(),
                        TextInput::make('city')->label(__('field.fields.city'))->helperText(__('pedidos.settings.city_help'))->maxLength(120),
                        TextInput::make('state')->label(__('field.fields.state'))->maxLength(60),
                        MapPoint::make()->columnSpanFull(),
                    ]),
                Section::make(__('pedidos.settings.orders'))
                    ->columns(['default' => 1, 'sm' => 2])
                    ->components([
                        PedidosFields::money('delivery_fee_cents')->label(__('pedidos.fields.delivery_fee'))->helperText(__('pedidos.settings.fee_help')),
                    ]),
                Section::make(__('pedidos.settings.rhythm'))
                    ->description(__('pedidos.settings.rhythm_help'))
                    ->columns(['default' => 1, 'sm' => 3])
                    ->components([
                        TextInput::make('late_percent')->label(__('pedidos.settings.late_percent'))->numeric()->integer()->minValue(100)->maxValue(1000)->suffix('%')->required(),
                        TextInput::make('stopped_percent')->label(__('pedidos.settings.stopped_percent'))->numeric()->integer()->minValue(100)->maxValue(2000)->suffix('%')->required()->gte('late_percent'),
                        TextInput::make('min_orders')->label(__('pedidos.settings.min_orders'))->numeric()->integer()->minValue(2)->maxValue(20)->required(),
                        Textarea::make('promo_message')->label(__('pedidos.missing.message'))->helperText(__('pedidos.missing.message_help'))->rows(3)->maxLength(1000)->columnSpanFull(),
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
                        Action::make('save')->label(__('pedidos.settings.save'))->submit('save'),
                    ]),
                ]),
        ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $settings = PedidosSetting::current();
        $state = $this->form->getState();
        $state['promo_message'] = filled($state['promo_message'] ?? null) && trim((string) $state['promo_message']) !== __('pedidos.messages.promo_default') ? trim((string) $state['promo_message']) : null;
        $settings->update($state + ['configured_at' => $settings->configured_at ?? now()]);

        Notification::make()->success()->title(__('pedidos.settings.saved'))->send();
    }
}
