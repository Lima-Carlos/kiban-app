<?php

namespace App\Modules\Pedidos\Filament;

use App\Modules\Concerns\InModuleMenu;
use App\Modules\Pedidos\Enums\PedidosRole;
use App\Modules\Pedidos\Enums\Rhythm;
use App\Modules\Pedidos\Filament\Clients\ClientResource;
use App\Modules\Pedidos\Models\Client;
use App\Modules\Pedidos\Models\PedidosSetting;
use App\Modules\Pedidos\PedidosModule;
use App\Modules\Pedidos\Services\CustomerRhythm;
use App\Modules\Pedidos\Services\PedidosAccess;
use App\Modules\Pedidos\Services\PedidosFormat;
use App\Tenancy\TenantContext;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * The customers' map (OpenStreetMap, free): each house colored by the rhythm (green on rhythm, yellow late, red
 * stopped, gray new) and the shop. Shows where the customers are and which streets went quiet.
 */
class ClientsMapPage extends Page
{
    use InModuleMenu;

    protected static string $moduleKey = PedidosModule::KEY;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static ?int $navigationSort = 36;

    protected static ?string $slug = 'pedidos/mapa';

    protected string $view = 'pedidos.map';

    public static function canAccess(): bool
    {
        $tenant = app(TenantContext::class)->current();
        $role = app(PedidosAccess::class)->role();

        return $tenant !== null && $tenant->license()->hasModule(PedidosModule::KEY) && ($role->takesOrders() || $role === PedidosRole::Viewer);
    }

    public static function getNavigationLabel(): string
    {
        return __('pedidos.map.page_title');
    }

    public function getTitle(): string
    {
        return __('pedidos.map.page_title');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /**
     * @return array{pins: array<int, array<string, mixed>>, shop: ?array<string, float>, counts: array<string, int>, without_point: int}
     */
    public function data(): array
    {
        $rhythms = app(CustomerRhythm::class)->all();
        $clients = Client::query()->active()->with('mainSite')->get();
        $counts = collect(Rhythm::cases())->mapWithKeys(fn (Rhythm $rhythm): array => [$rhythm->value => 0])->all();
        $pins = [];
        $without = 0;

        foreach ($clients as $client) {
            $row = $rhythms->get($client->getKey());
            $rhythm = $row['rhythm'] ?? Rhythm::New;
            $counts[$rhythm->value]++;

            if (! $client->mainSite?->hasPoint()) {
                $without++;

                continue;
            }

            $pins[] = [
                'lat' => $client->mainSite->latitude,
                'lng' => $client->mainSite->longitude,
                'color' => $rhythm->hex(),
                'title' => $client->name.' · '.$rhythm->getLabel().' · '.PedidosFormat::rhythmLine($row),
                'url' => ClientResource::getUrl('view', ['record' => $client]),
            ];
        }

        $settings = PedidosSetting::current();

        return [
            'pins' => $pins,
            'shop' => $settings->hasPoint() ? ['lat' => $settings->latitude, 'lng' => $settings->longitude] : null,
            'counts' => $counts,
            'without_point' => $without,
        ];
    }
}
