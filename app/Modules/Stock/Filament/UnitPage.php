<?php

namespace App\Modules\Stock\Filament;

use App\Modules\Stock\Models\Movement;
use App\Modules\Stock\Models\Unit;
use App\Modules\Stock\Services\StockAccess;
use App\Modules\Stock\StockModule;
use App\Tenancy\TenantContext;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * The card of one unit tracked by serial number, opened by its QR label: what it is, where it is now (a place or
 * a customer) and its history. For who works with the stock (technicians included).
 */
class UnitPage extends Page
{
    protected static ?string $slug = 'estoque/equipamento';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'stock.unit';

    #[Url(as: 'equipamento')]
    public int $unit = 0;

    public static function canAccess(): bool
    {
        $tenant = app(TenantContext::class)->current();

        return $tenant !== null && $tenant->license()->hasModule(StockModule::KEY)
            && in_array(app(StockAccess::class)->role(), [StockModule::MANAGER, StockModule::VIEWER, StockModule::TECHNICIAN], true);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        abort_if($this->record() === null, 404);
    }

    public function record(): ?Unit
    {
        return Unit::query()->with(['item', 'location', 'customer'])->find($this->unit);
    }

    /** @return Collection<int, Movement> */
    public function history(): Collection
    {
        return Movement::query()->with(['from', 'to', 'workOrder', 'user'])->where('unit_id', $this->unit)->orderByDesc('moved_at')->limit(20)->get();
    }

    public function getTitle(): string
    {
        return (string) $this->record()?->serial;
    }
}
