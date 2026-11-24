<?php

namespace App\Modules\Pedidos\Services;

use App\Modules\Pedidos\Models\Order;
use App\Modules\Pedidos\Models\PedidosSetting;
use Carbon\CarbonInterface;

/**
 * How the Pedidos shows times, dates and the rhythm: the company's time zone, the person's language.
 */
class PedidosFormat
{
    /** "19:30" in the company's time zone. */
    public static function time(?CarbonInterface $moment): string
    {
        return $moment ? $moment->setTimezone(PedidosSetting::timezone())->format('H:i') : '';
    }

    /** "20/11/2026" (the person's language). */
    public static function date(?CarbonInterface $moment): string
    {
        return $moment ? $moment->setTimezone(PedidosSetting::timezone())->format(__('app.date_format')) : '';
    }

    /** "20/11 19:30". */
    public static function moment(?CarbonInterface $moment): string
    {
        return $moment ? $moment->setTimezone(PedidosSetting::timezone())->format(__('pedidos.format.moment')) : '';
    }

    /** "1 h 05 min" or "42 min". */
    public static function minutes(?int $minutes): string
    {
        if ($minutes === null) {
            return '—';
        }

        return $minutes >= 60 ? sprintf('%d h %02d min', intdiv($minutes, 60), $minutes % 60) : $minutes.' min';
    }

    /** Days as people say them: "3 dias", "12 dias" (rounded). */
    public static function days(float $days): string
    {
        $rounded = max(0, (int) round($days));

        return trans_choice('pedidos.format.days', $rounded, ['count' => $rounded]);
    }

    /** "hoje", "há 1 dia", "há 12 dias" (whole days passed). */
    public static function ago(float $days): string
    {
        $whole = max(0, (int) floor($days));

        return trans_choice('pedidos.format.ago', $whole, ['count' => $whole]);
    }

    /**
     * "Pede a cada 7 dias · último há 12 dias", or "2 pedidos · último há 3 dias" while new.
     *
     * @param  array<string, mixed>|null  $row  a row of CustomerRhythm
     */
    public static function rhythmLine(?array $row): ?string
    {
        if ($row === null) {
            return __('pedidos.clients.never_ordered');
        }

        $since = static::ago($row['days_since']);

        return $row['average_days'] === null
            ? trans_choice('pedidos.clients.rhythm_new', $row['orders'], ['count' => $row['orders'], 'since' => $since])
            : __('pedidos.clients.rhythm_line', ['every' => static::days($row['average_days']), 'since' => $since]);
    }

    /**
     * "#12 · 20/11 19:30 · 2× Pizza · preparou Ana · entregou Beto · 42 min · Entregue".
     */
    public static function orderLine(Order $order): string
    {
        return collect([
            '#'.$order->number.' · '.static::moment($order->created_at),
            $order->summary(),
            $order->preparer ? __('pedidos.orders.prepared_by', ['name' => $order->preparer->name]) : null,
            $order->deliverer ? __('pedidos.orders.delivered_by', ['name' => $order->deliverer->name]) : null,
            $order->delivered_at ? __('pedidos.orders.took', ['time' => static::minutes($order->minutes())]) : null,
            $order->status->getLabel(),
        ])->filter()->implode(' · ');
    }
}
