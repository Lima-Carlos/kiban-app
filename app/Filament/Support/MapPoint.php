<?php

namespace App\Filament\Support;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Grid;

/**
 * A point on the map in a form: latitude and longitude, the "use my location" button (the phone's or
 * computer's GPS, at the place) and a link to see it on OpenStreetMap. Nobody needs to type coordinates.
 */
class MapPoint
{
    public static function make(string $latitude = 'latitude', string $longitude = 'longitude'): Grid
    {
        return Grid::make(['default' => 1, 'sm' => 2])->components([
            ViewField::make('map_point_picker')
                ->view('filament.forms.map-point', ['latitudeField' => $latitude, 'longitudeField' => $longitude])
                ->dehydrated(false)
                ->columnSpanFull(),
            TextInput::make($latitude)
                ->label(__('app.map.latitude'))
                ->numeric()
                ->minValue(-90)
                ->maxValue(90)
                ->requiredWith($longitude),
            TextInput::make($longitude)
                ->label(__('app.map.longitude'))
                ->numeric()
                ->minValue(-180)
                ->maxValue(180)
                ->requiredWith($latitude),
        ]);
    }
}
