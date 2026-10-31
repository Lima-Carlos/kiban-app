<?php

namespace App\Filament\Support;

use App\Modules\ModulePolicy;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * For list pages whose "New" button waits for a first step (e.g. work orders wait for the service types):
 * the empty list repeats that step with a link to it, instead of "open the first one with the button above"
 * (there is no button yet).
 */
trait EmptyListShowsPendingStep
{
    public function table(Table $table): Table
    {
        $step = $this->pendingStepBeforeCreate();

        if ($step === null) {
            return $table;
        }

        return $table
            ->emptyStateIcon(Heroicon::OutlinedListBullet)
            ->emptyStateHeading(__('app.empty.step', ['step' => $step['label']]))
            ->emptyStateDescription($step['description'])
            ->emptyStateActions([
                Action::make('pendingStep')
                    ->label(__('app.home.do_it'))
                    ->url($step['url'])
                    ->visible(filled($step['url'])),
            ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function pendingStepBeforeCreate(): ?array
    {
        $resource = static::getResource();
        $policy = Gate::getPolicyFor($resource::getModel());

        $response = $resource::getCreateAuthorizationResponse();

        // Only when a step is what blocks (not read-only, a plan limit or the person's role).
        if (! $policy instanceof ModulePolicy || $response->allowed() || ! str_starts_with((string) $response->message(), Str::before(__('app.denied.step'), ':step'))) {
            return null;
        }

        return $policy->pendingStep();
    }
}
