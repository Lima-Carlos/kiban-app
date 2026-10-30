<?php

namespace App\Http\Controllers\Field;

use App\Http\Controllers\Controller;
use App\Modules\Field\Enums\OrderStatus;
use App\Modules\Field\FieldModule;
use App\Modules\Field\Models\WorkOrder;
use App\Modules\Field\Services\OrderReport;
use App\Tenancy\TenantContext;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * The customer's page of a work order report (no login, signed link) and its PDF. Only done orders have a report.
 */
class OrderReportController extends Controller
{
    public function show(int $order, OrderReport $reports): View
    {
        return view('field.report', $reports->data($this->order($order)));
    }

    public function pdf(int $order, OrderReport $reports): Response
    {
        $record = $this->order($order);

        return response($reports->pdf($record), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$reports->fileName($record).'"',
        ]);
    }

    protected function order(int $id): WorkOrder
    {
        abort_unless(app(TenantContext::class)->get()->license()->hasModule(FieldModule::KEY), 404);

        $order = WorkOrder::query()->find($id);
        abort_if($order === null || $order->status !== OrderStatus::Done, 404);

        return $order;
    }
}
