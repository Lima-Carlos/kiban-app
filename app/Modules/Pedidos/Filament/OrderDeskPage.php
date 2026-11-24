<?php

namespace App\Modules\Pedidos\Filament;

use App\Modules\Concerns\InModuleMenu;
use App\Modules\Field\Models\Site;
use App\Modules\Pedidos\Enums\Fulfillment;
use App\Modules\Pedidos\Enums\OrderSource;
use App\Modules\Pedidos\Enums\PaymentMethod;
use App\Modules\Pedidos\Filament\Orders\OrderResource;
use App\Modules\Pedidos\Models\Client;
use App\Modules\Pedidos\Models\MenuItem;
use App\Modules\Pedidos\Models\Order;
use App\Modules\Pedidos\Models\PedidosSetting;
use App\Modules\Pedidos\PedidosModule;
use App\Modules\Pedidos\Services\ClientService;
use App\Modules\Pedidos\Services\CustomerRhythm;
use App\Modules\Pedidos\Services\OrderService;
use App\Modules\Pedidos\Services\PedidosAccess;
use App\Modules\Pedidos\Services\PedidosException;
use App\Tenancy\TenantContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The quick order screen, made to be used while on the phone: type the phone and the customer appears (name,
 * address, last order, rhythm, "repeat the usual"); a new number asks only the name and the address. Then tap the
 * items of the menu (each line with its free text: "sem cebola", "meio a meio"), delivery or pickup, payment,
 * where it came from (phone, WhatsApp, counter, iFood/other app: history only) and save. The order goes to the board.
 */
class OrderDeskPage extends Page
{
    use InModuleMenu;

    protected static string $moduleKey = PedidosModule::KEY;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPlusCircle;

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'pedidos/novo';

    protected string $view = 'pedidos.desk';

    public string $phone = '';

    public ?int $clientId = null;

    /** The number typed was searched (so the "new customer" fields can show). */
    public bool $searched = false;

    public string $clientName = '';

    public ?int $siteId = null;

    /** Typing another address for a known customer. */
    public bool $newAddress = false;

    /** @var array<string, string> */
    public array $address = ['street' => '', 'number' => '', 'complement' => '', 'district' => '', 'reference' => ''];

    public bool $consent = false;

    /** @var array<int, array{menu_item_id: ?int, name: string, unit_price_cents: int, quantity: int, notes: string}> */
    public array $cart = [];

    public string $fulfillment = 'delivery';

    public string $source = 'phone';

    public string $payment = '';

    public string $changeFor = '';

    public string $fee = '';

    public string $notes = '';

    public string $menuSearch = '';

    public static function canAccess(): bool
    {
        $tenant = app(TenantContext::class)->current();

        return $tenant !== null && $tenant->license()->canWrite(PedidosModule::KEY) && app(PedidosAccess::class)->takesOrders();
    }

    public static function getNavigationLabel(): string
    {
        return __('pedidos.desk.page_title');
    }

    public function getTitle(): string
    {
        return __('pedidos.desk.page_title');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->resetOrder();

        if (filled($phone = request()->query('telefone'))) {
            $this->phone = (string) $phone;
            $this->lookup();
        }
    }

    /** Why an order cannot be taken now (a first step missing), or null. */
    public function blocked(): ?string
    {
        $response = OrderResource::getCreateAuthorizationResponse();

        return $response->denied() ? $response->message() : null;
    }

    public function lookup(): void
    {
        $client = app(ClientService::class)->findByPhone($this->phone);
        $this->clientId = $client?->getKey();
        $this->searched = filled(trim($this->phone));
        $this->clientName = $client?->name ?? '';
        $this->siteId = $client?->mainSite?->getKey();
        $this->newAddress = $client !== null && $client->mainSite === null;
        $this->consent = (bool) $client?->details?->whatsapp_consent;

        if ($client !== null && $this->payment === '' && $client->details?->payment_preference !== null) {
            $this->payment = $client->details->payment_preference->value;
        }
    }

    public function updatedPhone(): void
    {
        if (strlen((string) preg_replace('/\D/', '', $this->phone)) >= 8) {
            $this->lookup();
        } else {
            $this->clientId = null;
            $this->searched = false;
        }
    }

    public function client(): ?Client
    {
        return $this->clientId ? Client::query()->with(['sites', 'details'])->find($this->clientId) : null;
    }

    public function lastOrder(): ?Order
    {
        return $this->client()?->lastOrder();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function rhythm(): ?array
    {
        return $this->clientId ? app(CustomerRhythm::class)->for($this->clientId) : null;
    }

    /**
     * Active items by category (searched by name).
     *
     * @return Collection<string, Collection<int, MenuItem>>
     */
    public function menu(): Collection
    {
        $search = trim($this->menuSearch);

        return MenuItem::query()->active()
            ->when($search !== '', fn ($query) => $query->where('name', 'ilike', '%'.$search.'%'))
            ->orderBy('category')->orderBy('sort')->orderBy('name')->get()
            ->groupBy(fn (MenuItem $item): string => $item->category ?: __('pedidos.desk.no_category'));
    }

    public function add(int $itemId): void
    {
        $item = MenuItem::query()->active()->find($itemId);

        if ($item === null) {
            return;
        }

        foreach ($this->cart as $index => $line) {
            if ($line['menu_item_id'] === $item->getKey() && $line['notes'] === '') {
                $this->cart[$index]['quantity']++;

                return;
            }
        }

        $this->cart[] = ['menu_item_id' => $item->getKey(), 'name' => $item->name, 'unit_price_cents' => $item->price_cents, 'quantity' => 1, 'notes' => ''];
    }

    public function more(int $index): void
    {
        if (isset($this->cart[$index])) {
            $this->cart[$index]['quantity'] = min(999, $this->cart[$index]['quantity'] + 1);
        }
    }

    public function less(int $index): void
    {
        if (! isset($this->cart[$index])) {
            return;
        }

        if ($this->cart[$index]['quantity'] <= 1) {
            unset($this->cart[$index]);
            $this->cart = array_values($this->cart);

            return;
        }

        $this->cart[$index]['quantity']--;
    }

    /** "Repeat the usual": the customer's most repeated order, with today's menu prices. */
    public function repeatUsual(): void
    {
        $client = $this->client();

        if ($client === null) {
            return;
        }

        $prices = MenuItem::query()->pluck('price_cents', 'id');
        $this->cart = collect(app(ClientService::class)->usual($client))->map(fn (array $line): array => [
            'menu_item_id' => $line['menu_item_id'],
            'name' => $line['name'],
            'unit_price_cents' => $line['menu_item_id'] !== null ? (int) ($prices[$line['menu_item_id']] ?? $line['unit_price_cents']) : $line['unit_price_cents'],
            'quantity' => $line['quantity'],
            'notes' => (string) $line['notes'],
        ])->values()->all();
    }

    public function subtotal(): int
    {
        return (int) collect($this->cart)->sum(fn (array $line): int => $line['unit_price_cents'] * $line['quantity']);
    }

    public function feeCents(): int
    {
        return $this->fulfillment === Fulfillment::Delivery->value ? $this->cents($this->fee) : 0;
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        if (($blocked = $this->blocked()) !== null) {
            Notification::make()->danger()->title($blocked)->send();

            return;
        }

        try {
            $order = DB::transaction(function (): Order {
                $clients = app(ClientService::class);
                $client = $this->client();
                $siteId = $this->siteId;

                if ($client === null && filled(trim($this->clientName))) {
                    $client = $clients->create(['name' => $this->clientName, 'phone' => $this->phone, 'whatsapp_consent' => $this->consent, 'payment_preference' => $this->payment ?: null] + $this->address);
                    $siteId = $client->mainSite?->getKey();
                } elseif ($client !== null) {
                    if ($this->newAddress && ($site = $clients->addSite($client, $this->address)) instanceof Site) {
                        $siteId = $site->getKey();
                    }

                    $clients->saveDetails($client, ['whatsapp_consent' => $this->consent] + ($client->details?->payment_preference === null && $this->payment !== '' ? ['payment_preference' => $this->payment] : []));
                }

                return app(OrderService::class)->create([
                    'customer_id' => $client?->getKey(),
                    'site_id' => $siteId,
                    'fulfillment' => $this->fulfillment,
                    'source' => $this->source,
                    'payment_method' => $this->payment ?: null,
                    'change_for_cents' => filled($this->changeFor) ? $this->cents($this->changeFor) : null,
                    'delivery_fee_cents' => $this->feeCents(),
                    'notes' => $this->notes,
                    'lines' => $this->cart,
                ], auth()->user());
            });
        } catch (PedidosException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            return;
        }

        Notification::make()->success()
            ->title(__('pedidos.desk.saved', ['number' => $order->number]))
            ->actions([
                Action::make('ticket')->label(__('pedidos.ticket.button'))->url(route('pedidos.ticket', ['order' => $order->getKey()]), shouldOpenInNewTab: true),
            ])
            ->send();

        $this->resetOrder();
    }

    public function resetOrder(): void
    {
        $settings = PedidosSetting::current();
        $this->reset(['phone', 'clientId', 'searched', 'clientName', 'siteId', 'newAddress', 'address', 'consent', 'cart', 'payment', 'changeFor', 'notes', 'menuSearch']);
        $this->fulfillment = Fulfillment::Delivery->value;
        $this->source = OrderSource::Phone->value;
        $this->fee = $settings->delivery_fee_cents > 0 ? number_format($settings->delivery_fee_cents / 100, 2, '.', '') : '';
    }

    /**
     * @return array<string, string>
     */
    public function payments(): array
    {
        return PaymentMethod::options();
    }

    /** "39,90" or "39.90" typed → 3990. */
    protected function cents(string $typed): int
    {
        $typed = trim(str_replace(' ', '', $typed));

        if ($typed === '') {
            return 0;
        }

        if (str_contains($typed, ',')) {
            $typed = str_replace(['.', ','], ['', '.'], $typed);
        }

        return max(0, (int) round((float) $typed * 100));
    }
}
