<?php

namespace App\Modules\Rotina\Filament\Entries\Pages;

use App\Filament\Support\ModuleRecordsPage;
use App\Modules\Rotina\Filament\Entries\EntryResource;
use App\Modules\Rotina\Models\Entry;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Support\Icons\Heroicon;

class ManageEntries extends ModuleRecordsPage
{
    protected static string $resource = EntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('start')
                ->label(__('rotina.actions.start'))
                ->icon(Heroicon::OutlinedPlay)
                ->color('success')
                ->visible(fn (): bool => ! $this->running() && EntryResource::canCreate())
                ->schema([
                    Select::make('project_id')->label(__('rotina.fields.project'))->options(fn (): array => EntryResource::projectOptions())->searchable(),
                ])
                ->action(function (array $data): void {
                    Entry::query()->running()->where('user_id', auth()->id())->get()->each->stop();
                    Entry::query()->create(['user_id' => auth()->id(), 'project_id' => $data['project_id'] ?? null, 'worked_on' => now()->toDateString(), 'minutes' => 0, 'started_at' => now()]);
                }),
            Action::make('stop')
                ->label(__('rotina.actions.stop'))
                ->icon(Heroicon::OutlinedStop)
                ->color('danger')
                ->visible(fn (): bool => $this->running())
                ->action(fn () => Entry::query()->running()->where('user_id', auth()->id())->get()->each->stop()),
            CreateAction::make()->mutateDataUsing(fn (array $data): array => EntryResource::withMinutes($data) + ['user_id' => auth()->id()]),
        ];
    }

    protected function running(): bool
    {
        return Entry::query()->running()->where('user_id', auth()->id())->exists();
    }
}
