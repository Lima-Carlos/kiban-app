<?php

namespace App\Modules\Pedidos\Services;

use App\Support\Geo;

/**
 * The order to visit the deliveries of a run, with no paid map service:
 * - start at the shop and always go to the nearest stop not visited yet (straight-line distance);
 * - then try swapping pieces of the way (2-opt) while that makes the whole way shorter, so the order of the
 *   orders (who called first) never forces a longer way;
 * - stops without a point on the map go at the end, in the order they came.
 * The distance is in a straight line (the real way on the streets is longer); good enough to choose the order.
 */
class RoutePlanner
{
    /**
     * @param  array{0: float, 1: float}|null  $origin  the shop [latitude, longitude]
     * @param  array<int|string, array{0: float, 1: float}|null>  $stops  id => [latitude, longitude] (null: no point)
     * @return array{order: array<int, int|string>, distance: int, missing: array<int, int|string>}
     */
    public function plan(?array $origin, array $stops): array
    {
        $located = array_filter($stops, fn (?array $point): bool => $point !== null);
        $missing = array_keys(array_diff_key($stops, $located));

        if ($located === []) {
            return ['order' => $missing, 'distance' => 0, 'missing' => $missing];
        }

        // Without the shop's point, the way starts at the first stop.
        $start = $origin ?? reset($located);
        $order = $this->nearestNeighbour($start, $located);
        $order = $this->twoOpt($start, $order, $located);

        return [
            'order' => array_merge($order, $missing),
            'distance' => $this->length($start, $order, $located),
            'missing' => $missing,
        ];
    }

    /**
     * Google Maps with the stops in order (opens the app on the driver's phone). Without the shop's point, the
     * phone's location is the start.
     *
     * @param  array{0: float, 1: float}|null  $origin
     * @param  array<int, array{0: float, 1: float}>  $points  in visiting order
     */
    public function mapsUrl(?array $origin, array $points): ?string
    {
        $points = array_values($points);

        if ($points === []) {
            return null;
        }

        $format = fn (array $point): string => sprintf('%.6F,%.6F', $point[0], $point[1]);
        $destination = array_pop($points);
        $query = ['api' => 1, 'destination' => $format($destination), 'travelmode' => 'driving'];

        if ($origin !== null) {
            $query['origin'] = $format($origin);
        }

        if ($points !== []) {
            $query['waypoints'] = implode('|', array_map($format, $points));
        }

        return 'https://www.google.com/maps/dir/?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param  array{0: float, 1: float}  $start
     * @param  array<int|string, array{0: float, 1: float}>  $points
     * @return array<int, int|string>
     */
    protected function nearestNeighbour(array $start, array $points): array
    {
        $order = [];
        $current = $start;

        while ($points !== []) {
            $best = null;
            $bestDistance = PHP_INT_MAX;

            foreach ($points as $id => $point) {
                $distance = Geo::distance($current[0], $current[1], $point[0], $point[1]);

                if ($distance < $bestDistance) {
                    [$best, $bestDistance] = [$id, $distance];
                }
            }

            $order[] = $best;
            $current = $points[$best];
            unset($points[$best]);
        }

        return $order;
    }

    /**
     * Reverses pieces of the way while that shortens it (open way: the start is fixed, the end is free).
     *
     * @param  array{0: float, 1: float}  $start
     * @param  array<int, int|string>  $order
     * @param  array<int|string, array{0: float, 1: float}>  $points
     * @return array<int, int|string>
     */
    protected function twoOpt(array $start, array $order, array $points): array
    {
        $count = count($order);
        $best = $this->length($start, $order, $points);
        $improved = true;
        $rounds = 0;

        while ($improved && $rounds++ < 50) {
            $improved = false;

            for ($i = 0; $i < $count - 1; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $candidate = array_merge(array_slice($order, 0, $i), array_reverse(array_slice($order, $i, $j - $i + 1)), array_slice($order, $j + 1));
                    $length = $this->length($start, $candidate, $points);

                    if ($length < $best) {
                        [$order, $best, $improved] = [$candidate, $length, true];
                    }
                }
            }
        }

        return $order;
    }

    /**
     * @param  array{0: float, 1: float}  $start
     * @param  array<int, int|string>  $order
     * @param  array<int|string, array{0: float, 1: float}>  $points
     */
    protected function length(array $start, array $order, array $points): int
    {
        $total = 0;
        $current = $start;

        foreach ($order as $id) {
            $total += Geo::distance($current[0], $current[1], $points[$id][0], $points[$id][1]);
            $current = $points[$id];
        }

        return $total;
    }
}
