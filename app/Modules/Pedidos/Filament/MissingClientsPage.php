<?php

namespace App\Modules\Pedidos\Filament;

use App\Modules\Concerns\InModuleMenu;
use App\Modules\Pedidos\Enums\Rhythm;
use App\Modules\Pedidos\Models\Client;
use App\Modules\Pedidos\Models\Order;
use App\Modules\Pedidos\Models\PedidosSetting;
use App\Modules\Pedidos\PedidosModule;
use App\Modules\Pedidos\Services\ClientService;
use App\Modules\Pedidos\Services\CustomerRhythm;
use App\Modules\Pedidos\Services\PedidosAccess;
use App\Modules\Pedidos\Services\PedidosMessages;
use App\Tenancy\TenantContext;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * "Sumidos": customers who stopped ordering (red) and, if wanted, the late ones (yellow), the ones who stopped most
 * recently first. Each one with the last orders (who prepared, who delivered, how long it took: a clue of why they
 * left) and the WhatsApp button with the "come back" message, editable at the top, only for customers who agreed
 * to receive offers.
 */
class MissingClientsPage extends Page
{
    use InModuleMenu;

    protected static string $moduleKey = PedidosModule::KEY;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserMinus;

    protected static ?int $navigationSort = 35;

    protected static ?string $slug = 'pedidos/sumidos';

    protected string $view = 'pedidos.missing';

    public string $message = '';

    public bool $withLate = false;

    public static function canAccess(): bool
    {
        $tenant = app(TenantContext::class)->current();

        return $tenant !== null && $tenant->license()->canWrite(PedidosModule::KEY) && app(PedidosAccess::class)->takesOrders();
    }

    public static function getNavigationLabel(): string
    {
        return __('pedidos.missing.page_title');
    }

    public function getTitle(): string
    {
        return __('pedidos.missing.page_title');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->message = PedidosSetting::current()->promoMessage();
    }

    /**
     * @return Collection<int, array{client: Client, row: array<string, mixed>, orders: Collection<int, Order>}>
     */
    public function clients(): Collection
    {
        $wanted = $this->withLate ? [Rhythm::Stopped, Rhythm::Late] : [Rhythm::Stopped];
        $rows = app(CustomerRhythm::class)->all()->filter(fn (array $row): bool => in_array($row['rhythm'], $wanted, true))
            ->sortByDesc(fn (array $row) => $row['stopped_at']?->getTimestamp() ?? 0);
        $clients = Client::query()->with('details')->active()->whereKey($rows->keys()->all())->get()->keyBy('id');

        return $rows->filter(fn (array $row): bool => $clients->has($row['customer_id']))
            ->map(fn (array $row): array => [
                'client' => $clients->get($row['customer_id']),
                'row' => $row,
                'orders' => Order::query()->with(['lines', 'preparer', 'deliverer'])->where('customer_id', $row['customer_id'])->latest('created_at')->limit(3)->get(),
            ])->values();
    }

    public function whatsapp(Client $client): ?string
    {
        return app(PedidosMessages::class)->promo($client, $this->message);
    }

    /** Keep the edited text as the company's message. */
    public function saveMessage(): void
    {
        abort_unless(static::canAccess(), 403);
        PedidosSetting::current()->update(['promo_message' => mb_substr(trim($this->message), 0, 1000) ?: null]);
        $this->message = PedidosSetting::current()->promoMessage();
    }

    public function markSent(int $id): void
    {
        abort_unless(static::canAccess(), 403);
        $client = Client::query()->findOrFail($id);
        app(ClientService::class)->saveDetails($client, []);
        $client->details()->update(['last_promo_at' => now()]);
    }
}
