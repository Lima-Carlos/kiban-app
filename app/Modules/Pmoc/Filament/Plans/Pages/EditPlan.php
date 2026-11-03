<?php

namespace App\Modules\Pmoc\Filament\Plans\Pages;

use App\Modules\Pmoc\Filament\Plans\PlanResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPlan extends EditRecord
{
    use ChecksEquipmentLimit;

    protected static string $resource = PlanResource::class;

    protected function beforeSave(): void
    {
        $this->checkEquipmentLimit();
    }

    /**
     * Before the first visit, a new start date moves the first visit too.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (! $this->getRecord()->visits()->exists() && filled($data['starts_on'] ?? null)) {
            $data['next_due_on'] = $data['starts_on'];
        }

        return $data;
    }

    protected function currentPlanKey(): ?int
    {
        return (int) $this->getRecord()->getKey();
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
