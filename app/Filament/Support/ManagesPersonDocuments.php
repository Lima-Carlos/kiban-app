<?php

namespace App\Filament\Support;

use App\Models\Tenant;
use App\Modules\Module;
use App\Modules\ModuleRegistry;
use App\People\Documents\DocumentType;
use App\People\Documents\PersonDocument;
use App\People\Documents\PersonDocumentService;
use App\People\Person;
use App\Tenancy\TenantContext;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;

/**
 * Pieces shared by the screens of the people's documents ("Expiry dates" and a person's documents): the form,
 * the columns and the buttons (open the file, renew, correct, delete).
 */
trait ManagesPersonDocuments
{
    /** Files over this size are refused (photos are reduced in the browser first). */
    protected const MAX_FILE_KB = 10240;

    protected function documents(): PersonDocumentService
    {
        return app(PersonDocumentService::class);
    }

    protected function tenant(): Tenant
    {
        return app(TenantContext::class)->get();
    }

    /** Like People: allowed while one of the company's systems is not read-only. */
    protected function tenantCanWrite(): bool
    {
        $modules = app(ModuleRegistry::class)->enabledFor($this->tenant());

        return $modules === [] || collect($modules)->contains(fn (Module $module): bool => $this->tenant()->license()->canWrite($module->key()));
    }

    /**
     * @return array<int, mixed>
     */
    protected function documentFields(bool $choosePerson = false, ?PersonDocument $editing = null): array
    {
        $service = $this->documents();

        $fillExpiry = function (Get $get, Set $set) use ($service): void {
            $expiry = $service->expiryFor(DocumentType::query()->find($get('document_type_id')), $get('issued_on'));

            if ($expiry !== null) {
                $set('expires_on', $expiry->toDateString());
            }
        };

        return [
            Select::make('person_id')
                ->label(__('documents.fields.person'))
                ->options(fn (): array => Person::query()->active()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->required()
                ->visible($choosePerson),
            Grid::make(['default' => 1, 'sm' => 2])->components([
                Select::make('document_type_id')
                    ->label(__('documents.fields.type'))
                    ->options(fn (): array => $service->typeOptions(activeOnly: $editing === null))
                    ->required()
                    ->disabled($editing !== null)
                    ->live()
                    ->afterStateUpdated($fillExpiry),
                TextInput::make('number')
                    ->label(__('documents.fields.number'))
                    ->maxLength(60),
                DatePicker::make('issued_on')
                    ->label(__('documents.fields.issued_on'))
                    ->maxDate(now()->addDay())
                    ->live(onBlur: true)
                    ->afterStateUpdated($fillExpiry),
                DatePicker::make('expires_on')
                    ->label(__('documents.fields.expires_on'))
                    ->afterOrEqual('issued_on')
                    ->helperText(function (Get $get): string {
                        $months = DocumentType::query()->find($get('document_type_id'))?->validity_months;

                        return $months !== null
                            ? trans_choice('documents.helpers.expiry_from_validity', $months, ['months' => $months])
                            : __('documents.helpers.expiry_typed');
                    }),
            ]),
            FileUpload::make('file_path')
                ->label(__('documents.fields.file'))
                ->helperText(__('documents.helpers.file'))
                ->disk('local')
                ->visibility('private')
                ->directory(fn (): string => 'tenants/'.$this->tenant()->getKey().'/people/documents')
                ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/heic'])
                ->automaticallyResizeImagesMode('contain')
                ->automaticallyResizeImagesToWidth('1600')
                ->automaticallyResizeImagesToHeight('1600')
                ->maxSize(self::MAX_FILE_KB),
            Textarea::make('notes')
                ->label(__('documents.fields.notes'))
                ->rows(2),
        ];
    }

    /**
     * @return array<int, TextColumn>
     */
    protected function documentColumns(bool $withPerson): array
    {
        return array_values(array_filter([
            $withPerson ? TextColumn::make('person.name')
                ->label(__('documents.fields.person'))
                ->weight('bold')
                ->description(fn (PersonDocument $record): ?string => $record->person->team?->name)
                ->searchable() : null,
            TextColumn::make('type_label')
                ->label(__('documents.fields.type'))
                ->state(fn (PersonDocument $record): string => $record->type->label())
                ->weight($withPerson ? null : 'bold')
                ->description(fn (PersonDocument $record): ?string => collect([
                    $record->number,
                    $record->type->is_health ? __('documents.fields.health_badge') : null,
                ])->filter()->implode(' · ') ?: null),
            TextColumn::make('issued_on')
                ->label(__('documents.fields.issued_on'))
                ->date(__('app.date_format'))
                ->placeholder('—')
                ->visibleFrom('md'),
            TextColumn::make('expires_on')
                ->label(__('documents.fields.expires_on'))
                ->date(__('app.date_format'))
                ->placeholder(__('documents.status.no_expiry'))
                ->sortable(),
            TextColumn::make('situation')
                ->label(__('documents.fields.situation'))
                ->state(fn (PersonDocument $record): string => $record->isCurrent() ? $record->statusText() : __('documents.status.replaced'))
                ->badge()
                ->color(fn (PersonDocument $record): string => $record->isCurrent() ? $record->status()->getColor() : 'gray'),
        ]));
    }

    /**
     * @return array<int, Action>
     */
    protected function documentRecordActions(): array
    {
        return [
            Action::make('openFile')
                ->label(__('documents.actions.open_file'))
                ->icon(Heroicon::OutlinedPaperClip)
                ->iconButton()
                ->visible(fn (PersonDocument $record): bool => $this->documents()->canOpenFile(auth()->user(), $record))
                ->url(fn (PersonDocument $record): string => route('people.documents.file', ['document' => $record->getKey()]))
                ->openUrlInNewTab(),
            Action::make('renew')
                ->label(__('documents.actions.renew'))
                ->icon(Heroicon::OutlinedArrowPath)
                ->iconButton()
                ->visible(fn (PersonDocument $record): bool => $record->isCurrent() && $record->person->is_active)
                ->authorize(fn (): bool => $this->documents()->canManage(auth()->user()) && $this->tenantCanWrite())
                ->modalHeading(fn (PersonDocument $record): string => __('documents.actions.renew_heading', ['document' => $record->type->label(), 'name' => $record->person->name]))
                ->modalDescription(__('documents.actions.renew_help'))
                ->modalWidth('2xl')
                ->fillForm(fn (PersonDocument $record): array => ['document_type_id' => $record->document_type_id])
                ->schema(fn (): array => $this->documentFields())
                ->action(function (PersonDocument $record, array $data): void {
                    $data['document_type_id'] = $record->document_type_id;
                    $this->documents()->add($record->person, $data, auth()->user());
                    Notification::make()->success()->title(__('documents.saved'))->send();
                }),
            Action::make('edit')
                ->label(__('documents.actions.edit'))
                ->icon(Heroicon::OutlinedPencilSquare)
                ->iconButton()
                ->authorize(fn (): bool => $this->documents()->canManage(auth()->user()) && $this->tenantCanWrite())
                ->modalWidth('2xl')
                ->fillForm(fn (PersonDocument $record): array => [
                    'document_type_id' => $record->document_type_id,
                    'number' => $record->number,
                    'issued_on' => $record->issued_on?->toDateString(),
                    'expires_on' => $record->expires_on?->toDateString(),
                    'file_path' => $record->file_path,
                    'notes' => $record->notes,
                ])
                ->schema(fn (PersonDocument $record): array => $this->documentFields(editing: $record))
                ->action(function (PersonDocument $record, array $data): void {
                    $this->documents()->update($record, $data, auth()->user());
                    Notification::make()->success()->title(__('documents.saved'))->send();
                }),
            Action::make('delete')
                ->label(__('documents.actions.delete'))
                ->icon(Heroicon::OutlinedTrash)
                ->iconButton()
                ->color('danger')
                ->authorize(fn (): bool => $this->documents()->canManage(auth()->user()) && $this->tenantCanWrite())
                ->requiresConfirmation()
                ->modalDescription(__('documents.actions.delete_help'))
                ->action(function (PersonDocument $record): void {
                    $this->documents()->delete($record);
                    Notification::make()->success()->title(__('documents.deleted'))->send();
                }),
        ];
    }

    /** "Add document" (for one person, or choosing the person). */
    protected function addDocumentAction(?Person $person = null): Action
    {
        return Action::make('addDocument')
            ->label(__('documents.actions.add'))
            ->icon(Heroicon::OutlinedPlus)
            ->authorize(fn (): bool => $this->documents()->canManage(auth()->user()) && $this->tenantCanWrite())
            ->modalWidth('2xl')
            ->schema(fn (): array => $this->documentFields(choosePerson: $person === null))
            ->action(function (array $data) use ($person): void {
                $owner = $person ?? Person::query()->findOrFail($data['person_id']);
                $this->documents()->add($owner, $data, auth()->user());
                Notification::make()->success()->title(__('documents.saved'))->send();
            });
    }
}
