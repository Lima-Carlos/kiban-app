<?php

namespace App\Modules\Pmoc\Filament;

use App\Modules\Field\Enums\FieldRole;
use App\Modules\Field\FieldModule;
use App\Modules\Field\Services\FieldAccess;
use App\Modules\Pmoc\Models\Equipment;
use App\Modules\Pmoc\PmocModule;
use App\Modules\Pmoc\Services\PmocAccess;
use App\Tenancy\TenantContext;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/**
 * The card of one piece of equipment, opened by the QR label stuck on it: what it is, the customer and the plan,
 * and its last visits (date, tasks, work order, technician). For the PMOC people and the Campo technicians.
 */
class EquipmentPage extends Page
{
    protected static ?string $slug = 'pmoc/equipamento';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'pmoc.equipment';

    #[Url(as: 'equipamento')]
    public int $equipment = 0;

    public static function canAccess(): bool
    {
        $tenant = app(TenantContext::class)->current();

        if ($tenant === null || ! $tenant->license()->hasModule(PmocModule::KEY)) {
            return false;
        }

        return app(PmocAccess::class)->canView()
            || ($tenant->license()->hasModule(FieldModule::KEY) && app(FieldAccess::class)->role() === FieldRole::Technician);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        abort_if($this->record() === null, 404);
    }

    public function record(): ?Equipment
    {
        return Equipment::query()->with(['plan.customer', 'plan.site', 'plan.visits.workOrder.technician'])->find($this->equipment);
    }

    public function getTitle(): string
    {
        return (string) $this->record()?->tag;
    }
}
