<?php

namespace App\Modules\Agenda\Filament\Professionals\Pages;

use App\Filament\Support\ModuleRecordsPage;
use App\Modules\Agenda\Filament\Professionals\ProfessionalResource;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

/**
 * Professionals: "Add professional" opens the People form, where the person gets "Attends on the agenda".
 */
class ManageProfessionals extends ModuleRecordsPage
{
    protected static string $resource = ProfessionalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('add')
                ->label(__('agenda.professionals.new'))
                ->icon(Heroicon::OutlinedPlus)
                ->visible(fn (): bool => ProfessionalResource::canCreate())
                ->url(fn (): string => ProfessionalResource::addUrl()),
        ];
    }
}
