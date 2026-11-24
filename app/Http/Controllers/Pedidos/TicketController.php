<?php

namespace App\Http\Controllers\Pedidos;

use App\Http\Controllers\Controller;
use App\Models\CompanyProfile;
use App\Modules\Pedidos\Models\Order;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Pedidos: the kitchen ticket of an order (a simple page made for small receipt printers, 80 mm or 58 mm, and for
 * any printer). The browser prints it; nothing to install. Only for who sees the orders of the company.
 */
class TicketController extends Controller
{
    public function __invoke(Request $request, int $order): View
    {
        $order = Order::query()->with(['client', 'lines', 'site'])->findOrFail($order);
        abort_unless($request->user()?->can('view', $order), 403);

        return view('pedidos.ticket', ['order' => $order, 'company' => CompanyProfile::current()->name()]);
    }
}
