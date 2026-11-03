<?php

namespace App\Modules\Pmoc\Filament\Plans\Pages;

use App\Filament\Support\NewRecordTitle;
use App\Modules\Pmoc\Filament\Plans\PlanResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePlan extends CreateRecord
{
    use ChecksEquipmentLimit, NewRecordTitle;

    protected static string $resource = PlanResource::class;

    protected function beforeCreate(): void
    {
        $this->checkEquipmentLimit();
    }

    /**
     * The first visit is on the start date.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $data + ['next_due_on' => $data['starts_on']];
    }

    protected function currentPlanKey(): ?int
    {
        return null;
    }
}
