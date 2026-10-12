<?php

namespace App\Modules\Fleet\Filament;

use App\Modules\Fleet\Models\FleetSetting;
use App\Modules\Fleet\Services\FleetFormat;
use App\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Validation\Rules\Unique;

/**
 * Pieces shared by the Frota forms.
 */
class FleetFields
{
    /** Photos are reduced on the phone before upload (about 300–500 KB each). */
    public const PHOTO_MAX_PIXELS = '1600';

    /** Per file, before reduction. */
    public const MAX_FILE_KB = 10240;

    /**
     * "Unique" only inside the company (two companies can have the same plate).
     */
    public static function sameTenant(): Closure
    {
        return fn (Unique $rule): Unique => $rule->where('tenant_id', app(TenantContext::class)->get()->getKey());
    }

    /**
     * Private photo of the company, reduced in the browser before sending.
     */
    public static function photo(string $field, string $folder): FileUpload
    {
        return FileUpload::make($field)
            ->image()
            ->disk('local')
            ->visibility('private')
            ->directory(fn (): string => static::directory($folder))
            ->automaticallyResizeImagesMode('contain')
            ->automaticallyResizeImagesToWidth(self::PHOTO_MAX_PIXELS)
            ->automaticallyResizeImagesToHeight(self::PHOTO_MAX_PIXELS)
            ->maxSize(self::MAX_FILE_KB);
    }

    /**
     * Private file (PDF or photo) of the company: documents, receipts.
     */
    public static function file(string $field, string $folder): FileUpload
    {
        return FileUpload::make($field)
            ->disk('local')
            ->visibility('private')
            ->directory(fn (): string => static::directory($folder))
            ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/heic'])
            ->automaticallyResizeImagesMode('contain')
            ->automaticallyResizeImagesToWidth(self::PHOTO_MAX_PIXELS)
            ->automaticallyResizeImagesToHeight(self::PHOTO_MAX_PIXELS)
            ->maxSize(self::MAX_FILE_KB)
            ->openable()
            ->downloadable();
    }

    /** Files of each company in their own folder. */
    public static function directory(string $folder): string
    {
        return 'tenants/'.app(TenantContext::class)->get()->getKey().'/fleet/'.$folder;
    }

    /**
     * Next due (distance and date) and the interval to calculate the following one: oil change or revision.
     *
     * @return array<int, TextInput|DatePicker>
     */
    public static function maintenancePlan(string $prefix, FleetSetting $settings): array
    {
        return [
            TextInput::make("next_{$prefix}_odometer")
                ->label(__('fleet.fields.next_at_odometer'))
                ->integer()
                ->minValue(0)
                ->maxValue(9_999_999)
                ->suffix($settings->distance_unit),
            DatePicker::make("next_{$prefix}_on")
                ->label(__('fleet.fields.next_on')),
            TextInput::make("{$prefix}_interval_distance")
                ->label(__('fleet.fields.interval_distance'))
                ->integer()
                ->minValue(100)
                ->maxValue(200_000)
                ->suffix($settings->distance_unit),
            TextInput::make("{$prefix}_interval_months")
                ->label(__('fleet.fields.interval_months'))
                ->integer()
                ->minValue(1)
                ->maxValue(60)
                ->suffix(__('fleet.fields.months')),
        ];
    }

    /** "At 45.000 km or on 10/03/2027". */
    public static function dueText(?int $odometer, ?CarbonInterface $date): string
    {
        $parts = array_filter([
            $odometer !== null ? __('fleet.helpers.due_at', ['value' => FleetFormat::distance($odometer)]) : null,
            $date !== null ? __('fleet.helpers.due_on', ['date' => $date->translatedFormat(__('app.date_format'))]) : null,
        ]);

        return $parts === [] ? __('fleet.helpers.due_not_set') : implode(' '.__('fleet.helpers.or').' ', $parts);
    }

    public static function timezone(): string
    {
        return FilamentTimezone::get();
    }
}
