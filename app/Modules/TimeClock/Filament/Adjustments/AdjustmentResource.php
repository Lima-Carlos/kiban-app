<?php

namespace App\Modules\TimeClock\Filament\Adjustments;

use App\Filament\Support\ModuleResource;
use App\Modules\TimeClock\Filament\TimesheetPage;
use App\Modules\TimeClock\Models\Adjustment;
use App\Modules\TimeClock\Services\AdjustmentService;
use App\Modules\TimeClock\TimeClockModule;
use App\People\PersonRuleException;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

/**
 * Corrections asked by employees (and the ones made by the manager): forgotten punch, punch left out,
 * excused time. The manager approves or rejects with an answer; the person hears about it.
 */
class AdjustmentResource extends ModuleResource
{
    protected static string $moduleKey = TimeClockModule::KEY;

    protected static ?string $model = Adjustment::class;

    protected static ?string $langGroup = 'timeclock.adjustments';

    protected static ?string $slug = 'ponto/correcoes';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPencilSquare;

    protected static ?int $navigationSort = 6;

    public static function getModelLabel(): string
    {
        return __('timeclock.adjustments.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('timeclock.adjustments.plural');
    }

    public static function getTitleCasePluralModelLabel(): string
    {
        return __('timeclock.adjustments.plural_title');
    }

    /** Menu badge: how many are waiting. */
    public static function getNavigationBadge(): ?string
    {
        $pending = Adjustment::query()->where('status', Adjustment::PENDING)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['employee.person', 'punch', 'requester', 'reviewer']);
    }

    public static function detail(Adjustment $record): string
    {
        $tz = $record->employee?->workplace?->timezone ?? config('kiban.timezone');

        return match ($record->type) {
            Adjustment::ADD_PUNCH => $record->punch_at?->setTimezone($tz)->format('H:i') ?? '',
            Adjustment::IGNORE_PUNCH => $record->punch ? $record->punch->localTime()->format('H:i').' · #'.$record->punch->nsr : '',
            default => $record->minutes === null ? __('timeclock.timesheet.excused_day') : $record->minutes.' min',
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('date')
                    ->label(__('timeclock.fields.date'))
                    ->date(__('app.date_format'))
                    ->sortable(),
                TextColumn::make('employee.person.name')
                    ->label(__('timeclock.fields.name'))
                    ->weight('bold')
                    ->searchable()
                    ->url(fn (Adjustment $record): string => TimesheetPage::getUrl(['employee' => $record->employee_id, 'month' => $record->date->format('Y-m')])),
                TextColumn::make('type')
                    ->label(__('timeclock.adjustments.what'))
                    ->formatStateUsing(fn (string $state): string => __('timeclock.adjustments.types.'.$state))
                    ->description(fn (Adjustment $record): string => static::detail($record)),
                TextColumn::make('reason')
                    ->label(__('timeclock.adjustments.reason'))
                    ->limit(60)
                    ->wrap()
                    ->description(fn (Adjustment $record): ?string => $record->response),
                TextColumn::make('status')
                    ->label(__('timeclock.adjustments.status'))
                    ->formatStateUsing(fn (string $state): string => __('timeclock.adjustments.statuses.'.$state))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        Adjustment::APPROVED => 'success',
                        Adjustment::REJECTED => 'gray',
                        default => 'warning',
                    })
                    ->description(fn (Adjustment $record): ?string => $record->requester?->name),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('timeclock.adjustments.status'))
                    ->options([
                        Adjustment::PENDING => __('timeclock.adjustments.statuses.pending'),
                        Adjustment::APPROVED => __('timeclock.adjustments.statuses.approved'),
                        Adjustment::REJECTED => __('timeclock.adjustments.statuses.rejected'),
                    ])
                    ->default(Adjustment::PENDING),
            ])
            ->recordActions([
                Action::make('attachment')
                    ->label(__('timeclock.adjustments.attachment'))
                    ->icon(Heroicon::OutlinedPaperClip)
                    ->link()
                    ->visible(fn (Adjustment $record): bool => filled($record->attachment_path))
                    ->url(fn (Adjustment $record): string => Storage::disk('local')->temporaryUrl($record->attachment_path, now()->addMinutes(10)), shouldOpenInNewTab: true),
                Action::make('approve')
                    ->label(__('timeclock.adjustments.approve'))
                    ->icon(Heroicon::OutlinedCheck)
                    ->color('success')
                    ->visible(fn (Adjustment $record): bool => $record->isPending())
                    ->authorize('update')
                    ->schema([Textarea::make('response')->label(__('timeclock.adjustments.response_optional'))->rows(2)])
                    ->action(fn (Adjustment $record, array $data, Action $action) => static::attempt(fn () => app(AdjustmentService::class)->approve($record, auth()->user(), $data['response'] ?? null), $action)),
                Action::make('reject')
                    ->label(__('timeclock.adjustments.reject'))
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('gray')
                    ->visible(fn (Adjustment $record): bool => $record->isPending())
                    ->authorize('update')
                    ->schema([Textarea::make('response')->label(__('timeclock.adjustments.response'))->rows(2)->required()])
                    ->action(fn (Adjustment $record, array $data, Action $action) => static::attempt(fn () => app(AdjustmentService::class)->reject($record, auth()->user(), (string) $data['response']), $action)),
            ])
            ->emptyStateHeading(__('timeclock.adjustments.empty'));
    }

    protected static function attempt(callable $callback, Action $action): void
    {
        try {
            $callback();
        } catch (PersonRuleException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();
            $action->halt();
        }

        Notification::make()->success()->title(__('timeclock.adjustments.reviewed'))->send();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAdjustments::route('/'),
        ];
    }
}
