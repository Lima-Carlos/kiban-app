<?php

namespace App\Modules\Stock\Filament;

use App\Modules\Concerns\InModuleMenu;
use App\Modules\Stock\Models\Balance;
use App\Modules\Stock\Models\Unit;
use App\Modules\Stock\Services\StockAccess;
use App\Modules\Stock\StockModule;
use App\Tenancy\TenantContext;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * "My stock" (technicians): what the technician carries, and the serialized units in their place. Read-only.
 */
class MyStockPage extends Page
{
    use InModuleMenu;

    protected static string $moduleKey = StockModule::KEY;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'estoque/meu-estoque';

    protected string $view = 'stock.my-stock';

    public static function canAccess(): bool
    {
        $tenant = app(TenantContext::class)->current();

        return $tenant !== null && $tenant->license()->hasModule(StockModule::KEY) && app(StockAccess::class)->ownLocation() !== null;
    }

    public static function getNavigationLabel(): string
    {
        return __('stock.my.title');
    }

    public function getTitle(): string
    {
        return __('stock.my.title');
    }

    /** @return Collection<int, Balance> */
    public function balances(): Collection
    {
        $location = app(StockAccess::class)->ownLocation();

        return $location === null ? collect() : Balance::query()->with('item')->where('location_id', $location->getKey())->where('quantity', '!=', 0)->get()->sortBy('item.name')->values();
    }

    /** @return Collection<int, Unit> */
    public function units(): Collection
    {
        $location = app(StockAccess::class)->ownLocation();

        return $location === null ? collect() : Unit::query()->with('item')->where('location_id', $location->getKey())->where('status', Unit::IN_STOCK)->orderBy('serial')->get();
    }
}
