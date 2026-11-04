<?php

namespace App\Http\Controllers;

use App\Models\CompanyProfile;
use App\Modules\Pmoc\Models\Plan;
use App\Modules\Stock\Filament\UnitPage;
use App\Modules\Stock\Models\Unit;
use App\Support\QrCode;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Sheets of labels to print (A4, 3 × 7, the usual sticker sheets): a QR code that opens the equipment's page (PMOC:
 * public, with "ask for service"; comodato: the card in the system, sign-in required), the company's name and the tag or serial number. The browser prints or saves as PDF.
 */
class LabelController extends Controller
{
    /** PMOC: one label per active equipment of a plan. */
    public function pmoc(Request $request, int $plan): View
    {
        $plan = Plan::query()->with('equipment', 'customer')->findOrFail($plan);
        abort_unless($request->user()?->can('viewAny', Plan::class), 403);

        $labels = $plan->equipment->where('is_active', true)->sortBy('tag')->map(fn ($item): array => [
            'qr' => QrCode::svg($item->publicUrl()),
            'title' => $item->tag,
            'lines' => [$item->room, collect([$item->brand, $item->model])->filter()->implode(' '), $item->serial ? __('pmoc.visit.serial', ['serial' => $item->serial]) : null],
        ])->values();

        return $this->sheet(__('pmoc.labels.title', ['plan' => $plan->name]), $labels->all());
    }

    /** Comodato: one label per unit in stock (optionally of one item or one place). */
    public function units(Request $request): View
    {
        abort_unless($request->user()?->can('viewAny', Unit::class), 403);

        $units = Unit::query()->with('item')->where('status', Unit::IN_STOCK)
            ->when($request->integer('item'), fn ($query, $item) => $query->where('item_id', $item))
            ->when($request->integer('location'), fn ($query, $location) => $query->where('location_id', $location))
            ->orderBy('item_id')->orderBy('serial')->limit(210)->get();

        $labels = $units->map(fn (Unit $unit): array => [
            'qr' => QrCode::svg(UnitPage::getUrl(['equipamento' => $unit->getKey()])),
            'title' => $unit->serial,
            'lines' => [$unit->item->name, $unit->mac],
        ])->all();

        return $this->sheet(__('stock.labels.title'), $labels);
    }

    /**
     * @param  array<int, array{qr: string, title: string, lines: array<int, ?string>}>  $labels
     */
    protected function sheet(string $title, array $labels): View
    {
        return view('labels.sheet', [
            'title' => $title,
            'labels' => $labels,
            'company' => CompanyProfile::current()->name(),
        ]);
    }
}
