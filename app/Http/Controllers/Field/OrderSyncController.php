<?php

namespace App\Http\Controllers\Field;

use App\Http\Controllers\Controller;
use App\Modules\Field\FieldModule;
use App\Modules\Field\Models\WorkOrder;
use App\Modules\Field\Services\FieldException;
use App\Modules\Field\Services\OrderExecutionService;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives the pieces of a work order from the technician's phone, one at a time, as soon as there is signal
 * (the phone keeps them in a queue meanwhile, like the time clock). Answers in JSON:
 * - "ok": applied (or already applied before);
 * - "refused": a rule said no (e.g. a required item missing to finish); the phone shows the reason;
 * - 401/403/419: not signed in or no access; the phone keeps the piece and tries again.
 */
class OrderSyncController extends Controller
{
    public function __invoke(Request $request, int $order, OrderExecutionService $execution): JsonResponse
    {
        abort_unless(app(TenantContext::class)->get()->license()->canWrite(FieldModule::KEY), 403);

        $request->validate([
            'piece' => ['required', 'array'],
            'piece.id' => ['required', 'uuid'],
            'piece.type' => ['required', 'string', 'in:'.implode(',', OrderExecutionService::TYPES)],
            'piece.taken_at' => ['nullable', 'integer', 'min:0'],
            'piece.data' => ['nullable', 'array'],
            'piece.data.image' => ['nullable', 'string', 'max:7000000'],
        ]);

        $record = WorkOrder::query()->find($order);
        abort_if($record === null, 404);

        try {
            $execution->apply($record, $request->user(), (array) $request->input('piece'));
        } catch (FieldException $exception) {
            return response()->json(['status' => 'refused', 'message' => $exception->getMessage(), 'now' => now()->getTimestampMs()]);
        }

        $record->refresh();

        return response()->json([
            'status' => 'ok',
            'order_status' => $record->status->value,
            'missing' => $execution->missing($record),
            'now' => now()->getTimestampMs(),
        ]);
    }
}
