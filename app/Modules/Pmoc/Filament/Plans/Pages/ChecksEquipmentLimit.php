<?php

namespace App\Modules\Pmoc\Filament\Plans\Pages;

use App\Modules\Pmoc\Models\Equipment;
use App\Modules\Pmoc\PmocModule;
use App\Tenancy\TenantContext;
use Filament\Notifications\Notification;

/**
 * The plan limit of the PMOC counts the active equipment of the company: saving a plan with more than the plan
 * allows is stopped with the reason.
 */
trait ChecksEquipmentLimit
{
    /** The plan being edited (null while creating): its own equipment is counted from the form. */
    abstract protected function currentPlanKey(): ?int;

    protected function checkEquipmentLimit(): void
    {
        $license = app(TenantContext::class)->get()->license();
        $max = $license->limitWithTolerance(PmocModule::KEY, PmocModule::LIMIT_EQUIPMENT);

        if ($max === null) {
            return;
        }

        $here = collect((array) ($this->data['equipment'] ?? []))->filter(fn ($item): bool => (bool) ($item['is_active'] ?? true))->count();
        $elsewhere = Equipment::query()->where('is_active', true)->when($this->currentPlanKey(), fn ($query, $plan) => $query->where('plan_id', '!=', $plan))->count();

        if ($here + $elsewhere > $max) {
            Notification::make()->danger()
                ->title(__('pmoc.denied.limit', ['limit' => $license->limit(PmocModule::KEY, PmocModule::LIMIT_EQUIPMENT)]))
                ->send();
            $this->halt();
        }
    }
}
