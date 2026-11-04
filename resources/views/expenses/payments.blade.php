{{-- "To pay back": approved claims by person, with one "Mark as paid" each. --}}
@php($rows = $this->rows())
@php($cell = 'padding:8px 10px;border-bottom:1px solid rgba(127,127,127,.2);text-align:left;font-size:14px')
<x-filament-panels::page>
    <x-filament::section :description="__('expenses.payments.help')">
        @if ($rows->isEmpty())
            <p style="margin:0;opacity:.7">{{ __('expenses.payments.none') }}</p>
        @else
            <div style="overflow-x:auto">
                <table style="width:100%;border-collapse:collapse" data-expenses="to-pay">
                    <thead>
                        <tr>
                            <th style="{{ $cell }}">{{ __('expenses.fields.person') }}</th>
                            <th style="{{ $cell }};text-align:right">{{ __('expenses.payments.claims') }}</th>
                            <th style="{{ $cell }};text-align:right">{{ __('expenses.payments.total') }}</th>
                            <th style="{{ $cell }}"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                <td style="{{ $cell }};font-weight:600">{{ $row['user']?->name }}</td>
                                <td style="{{ $cell }};text-align:right">{{ $row['count'] }}</td>
                                <td style="{{ $cell }};text-align:right;font-weight:700">{{ app(\App\Modules\Expenses\Services\ExpenseService::class)->money($row['total'], $row['currency']) }}</td>
                                <td style="{{ $cell }};text-align:right">
                                    <x-filament::button size="sm" color="success" icon="heroicon-m-check" wire:click="pay({{ $row['user']->getKey() }})" wire:confirm="{{ __('expenses.payments.confirm', ['name' => $row['user']->name]) }}">{{ __('expenses.payments.mark_paid') }}</x-filament::button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
