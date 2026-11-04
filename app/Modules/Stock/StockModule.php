<?php

namespace App\Modules\Stock;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\Field\Enums\FieldRole;
use App\Modules\Field\FieldModule;
use App\Modules\Field\Models\OrderMaterial;
use App\Modules\Field\Services\FieldAccess;
use App\Modules\Module;
use App\Modules\Step;
use App\Modules\Stock\Filament\Balances\BalanceResource;
use App\Modules\Stock\Filament\Items\ItemResource;
use App\Modules\Stock\Filament\Locations\LocationResource;
use App\Modules\Stock\Filament\Movements\MovementResource;
use App\Modules\Stock\Filament\MyStockPage;
use App\Modules\Stock\Filament\Units\UnitResource;
use App\Modules\Stock\Models\Balance;
use App\Modules\Stock\Models\Item;
use App\Modules\Stock\Models\Location;
use App\Modules\Stock\Models\Movement;
use App\Modules\Stock\Models\Unit;
use App\Modules\Stock\Policies\StockPolicy;
use App\Modules\Stock\Services\StockAccess;
use App\Modules\Stock\Services\StockFormat;
use App\Modules\Stock\Services\StockService;
use App\Tenancy\TenantContext;

/**
 * Kiban Core Estoque ("Inventory"): items, the warehouse and the stock each technician carries, transfers,
 * counts, and equipment tracked by serial number and lent to customers (comodato). With the Campo, what a
 * technician writes down on a work order leaves their own stock by itself.
 * Product code in the Core: "estoque"; plan limit: places (warehouses and technicians).
 */
class StockModule extends Module
{
    public const KEY = 'estoque';

    public const LIMIT_LOCATIONS = 'locations';

    public const MANAGER = 'manager';

    public const VIEWER = 'viewer';

    public const TECHNICIAN = 'technician';

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return __('stock.module');
    }

    public function icon(): string
    {
        return 'heroicon-o-archive-box';
    }

    public function steps(): array
    {
        return [
            new Step(
                key: 'location',
                labelKey: 'stock.steps.location',
                descriptionKey: 'stock.steps.location_help',
                done: fn (Tenant $tenant): bool => Location::query()->exists(),
                url: fn (): string => LocationResource::getUrl('index'),
            ),
            new Step(
                key: 'item',
                labelKey: 'stock.steps.item',
                descriptionKey: 'stock.steps.item_help',
                done: fn (Tenant $tenant): bool => Item::query()->exists(),
                url: fn (): string => ItemResource::getUrl('index'),
            ),
            new Step(
                key: 'entry',
                labelKey: 'stock.steps.entry',
                descriptionKey: 'stock.steps.entry_help',
                requires: ['location', 'item'],
                done: fn (Tenant $tenant): bool => Movement::query()->exists(),
                url: fn (): string => BalanceResource::getUrl('index'),
            ),
        ];
    }

    public function resources(): array
    {
        return [BalanceResource::class, UnitResource::class, ItemResource::class, LocationResource::class, MovementResource::class];
    }

    public function pages(): array
    {
        return [MyStockPage::class, Filament\UnitPage::class];
    }

    public function roles(): array
    {
        return [self::MANAGER => __('stock.roles.manager'), self::VIEWER => __('stock.roles.viewer'), self::TECHNICIAN => __('stock.roles.technician')];
    }

    /** The Campo office manages the stock; a technician sees their own; who only looks at the Campo only looks. */
    public function implicitRole(User $user): ?string
    {
        if (Location::query()->active()->where('kind', Location::TECHNICIAN)->where('user_id', $user->getKey())->exists()) {
            $technician = self::TECHNICIAN;
        }

        if (! app(TenantContext::class)->get()->license()->hasModule(FieldModule::KEY)) {
            return $technician ?? null;
        }

        return match (app(FieldAccess::class)->role($user)) {
            FieldRole::Manager, FieldRole::Dispatcher => self::MANAGER,
            FieldRole::Viewer => self::VIEWER,
            FieldRole::Technician => self::TECHNICIAN,
            default => $technician ?? null,
        };
    }

    public function showsSetupTo(User $user): bool
    {
        return app(StockAccess::class)->isManager($user);
    }

    /** Items below the minimum, for who manages the stock. */
    public function attention(User $user): array
    {
        if (! app(StockAccess::class)->isManager($user)) {
            return [];
        }

        return app(StockService::class)->lowStock()->take(5)->map(fn (array $low): array => [
            'text' => __('stock.attention.low', ['item' => $low['item']->name, 'total' => StockFormat::quantity($low['total'], $low['item']->unit), 'min' => StockFormat::quantity($low['item']->min_quantity, $low['item']->unit)]),
            'url' => BalanceResource::getUrl('index'),
            'color' => 'warning',
        ])->all();
    }

    /** Campo: a material written down on a work order leaves the technician's stock (and comes back if removed). */
    public function boot(): void
    {
        OrderMaterial::created(fn (OrderMaterial $material) => app(StockService::class)->fromFieldMaterial($material));
        OrderMaterial::deleted(fn (OrderMaterial $material) => app(StockService::class)->undoFieldMaterial($material));
    }

    public function policies(): array
    {
        return [
            Item::class => StockPolicy::class,
            Location::class => StockPolicy::class,
            Balance::class => StockPolicy::class,
            Unit::class => StockPolicy::class,
            Movement::class => StockPolicy::class,
        ];
    }
}
