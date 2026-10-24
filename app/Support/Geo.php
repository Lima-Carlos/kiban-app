<?php

namespace App\Support;

/**
 * Points on the map (latitude / longitude) shared by the modules: workplaces of the time clock, customer
 * addresses of the field service, the check-in of a technician.
 */
class Geo
{
    /** Distance in meters between two points (haversine). */
    public static function distance(float $latitude1, float $longitude1, float $latitude2, float $longitude2): int
    {
        $earth = 6371000;
        $latitudeDelta = deg2rad($latitude2 - $latitude1);
        $longitudeDelta = deg2rad($longitude2 - $longitude1);

        $a = sin($latitudeDelta / 2) ** 2 + cos(deg2rad($latitude1)) * cos(deg2rad($latitude2)) * sin($longitudeDelta / 2) ** 2;

        return (int) round($earth * 2 * atan2(sqrt($a), sqrt(1 - $a)));
    }

    /** Link to see the point on OpenStreetMap (free; nothing to configure). */
    public static function mapUrl(float $latitude, float $longitude): string
    {
        return sprintf('https://www.openstreetmap.org/?mlat=%1$.6F&mlon=%2$.6F#map=17/%1$.6F/%2$.6F', $latitude, $longitude);
    }
}
